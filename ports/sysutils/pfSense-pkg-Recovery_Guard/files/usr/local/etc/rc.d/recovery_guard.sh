#!/bin/sh
# PROVIDE: recovery_guard
# REQUIRE: LOGIN
# KEYWORD: shutdown

. /etc/rc.subr
name="recovery_guard"
rcvar="recovery_guard_enable"
pidfile="/var/run/recovery_guard.pid"
procname="/usr/local/bin/php"
script="/usr/local/pkg/recovery_guard/daemon.php"
start_cmd="recovery_guard_start"
stop_cmd="recovery_guard_stop"

recovery_guard_start()
{
    /usr/bin/timeout -k 1 12 "${procname}" "${script}" enabled
    result=$?
    [ "${result}" -eq 2 ] && return 0
    [ "${result}" -eq 0 ] || return 1
    /usr/sbin/daemon -c -f -p "${pidfile}" "${procname}" "${script}" run
}

recovery_guard_stop()
{
    service_pid=$(check_pidfile "${pidfile}" "${procname}")
    [ -n "${service_pid}" ] || return 0
    kill -TERM "${service_pid}" || return 1
    waited=0
    while [ -n "$(check_pidfile "${pidfile}" "${procname}")" ]; do
        [ "${waited}" -lt 35 ] || return 1
        sleep 1
        waited=$((waited + 1))
    done
}

load_rc_config "${name}"
: ${recovery_guard_enable:="YES"}
run_rc_command "$1"
