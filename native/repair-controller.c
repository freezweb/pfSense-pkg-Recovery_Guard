/* SPDX-License-Identifier: BSD-2-Clause */
/* A finite repair controller may leave successful service daemons running. */
#include <sys/types.h>
#include <sys/procctl.h>
#include <sys/wait.h>
#include <errno.h>
#include <fcntl.h>
#include <signal.h>
#include <stdint.h>
#include <stdio.h>
#include <stdlib.h>
#include <string.h>
#include <time.h>
#include <unistd.h>

static volatile sig_atomic_t interrupted;
static void stop(int sig) { (void)sig; interrupted = 1; }
static int64_t milliseconds(void)
{
    struct timespec t;
    if (clock_gettime(CLOCK_MONOTONIC, &t) != 0) return -1;
    return (int64_t)t.tv_sec * 1000 + t.tv_nsec / 1000000;
}
static void pause_tick(void)
{
    struct timespec t = { .tv_sec = 0, .tv_nsec = 10000000 };
    (void)nanosleep(&t, NULL);
}
static int report(const char *outcome, int code, int controller_exit)
{
    printf("{\"outcome\":\"%s\",\"controller_exit\":%d}\n", outcome, controller_exit);
    return code;
}
static int cleanup(void)
{
    int status;
    int64_t now = milliseconds(), end;
    if (now < 0) return 0;
    end = now + 3000;
    while ((now = milliseconds()) >= 0 && now < end) {
        struct procctl_reaper_kill request = {0};
        struct procctl_reaper_status state = {0};
        request.rk_sig = now < end - 2500 ? SIGTERM : SIGKILL;
        /* Kernel ownership avoids signalling recycled PIDs or unrelated processes. */
        (void)procctl(P_PID, getpid(), PROC_REAP_KILL, &request);
        while (waitpid(-1, &status, WNOHANG) > 0) {}
        if (procctl(P_PID, getpid(), PROC_REAP_STATUS, &state) != 0 ||
            !(state.rs_flags & REAPER_STATUS_OWNED)) return 0;
        if (state.rs_descendants == 0) return 1;
        pause_tick();
    }
    return 0;
}
int main(int argc, char **argv)
{
    char *native_argv[] = { "/etc/rc.php-fpm_restart", NULL };
    char **command = native_argv;
    char *environment[] = { "PATH=/sbin:/bin:/usr/sbin:/usr/bin:/usr/local/sbin:/usr/local/bin",
        "HOME=/", "LANG=C", "LC_ALL=C", NULL };
    int duration = 30000, status = 0, controller_exit = -1;
    int death_signal = SIGTERM;
    pid_t parent = getppid(), child;
    int64_t start, now;
    struct sigaction handler = {0};
    const char *failure = "timeout_cleaned";
    int failure_code = 124;

    if (geteuid() != 0) return report("unavailable", 126, -1);
#ifdef RECOVERY_GUARD_LAB
    /* Only a separately compiled test binary accepts fixture commands. */
    char *end;
    long requested;
    if (argc < 3 || argv[2][0] != '/') return report("unavailable", 126, -1);
    errno = 0;
    requested = strtol(argv[1], &end, 10);
    if (errno || *end || requested < 100 || requested > 30000) return report("unavailable", 126, -1);
    duration = (int)requested;
    command = &argv[2];
#else
    if (argc != 2 || strcmp(argv[1], "repair") != 0) return report("unavailable", 126, -1);
#endif
    closefrom(3);
    handler.sa_handler = stop;
    sigemptyset(&handler.sa_mask);
    if (sigaction(SIGTERM, &handler, NULL) != 0 || sigaction(SIGINT, &handler, NULL) != 0 ||
        sigaction(SIGHUP, &handler, NULL) != 0 ||
        procctl(P_PID, getpid(), PROC_PDEATHSIG_CTL, &death_signal) != 0 ||
        getppid() != parent || parent == 1 ||
        procctl(P_PID, getpid(), PROC_REAP_ACQUIRE, NULL) != 0 ||
        (start = milliseconds()) < 0) return report("unavailable", 125, -1);
    if (interrupted) return report("unavailable", 125, -1);
    child = fork();
    if (child < 0) return report("unavailable", 125, -1);
    if (child == 0) {
        int fd = open("/dev/null", O_RDWR);
        if (fd < 0) _exit(127);
        for (int i = 0; i < 3; i++) if (dup2(fd, i) < 0) _exit(127);
        closefrom(3);
        signal(SIGTERM, SIG_DFL); signal(SIGINT, SIG_DFL); signal(SIGHUP, SIG_DFL);
        execve(command[0], command, environment);
        _exit(127);
    }
    for (;;) {
        pid_t result;
        now = milliseconds();
        if (now < 0 || interrupted) { failure = "cancelled_cleaned"; failure_code = 70; break; }
        if (now - start >= duration) break;
        result = waitpid(child, &status, WNOHANG);
        if (result == child) {
            controller_exit = WIFEXITED(status) ? WEXITSTATUS(status) : -1;
            if (controller_exit == 0 && !interrupted) {
                if (procctl(P_PID, getpid(), PROC_REAP_RELEASE, NULL) != 0)
                    return report("cleanup_unknown", 125, controller_exit);
                /* No wait for daemon descendants; controller exit is NOT a health receipt. */
                return report("controller_exited", 0, 0);
            }
            failure = "failed_cleaned"; failure_code = 70; break;
        }
        if (result < 0 && errno != EINTR) { failure = "cancelled_cleaned"; failure_code = 70; break; }
        pause_tick();
    }
    if (!cleanup()) return report("cleanup_unknown", 125, controller_exit);
    return report(failure, failure_code, controller_exit);
}
