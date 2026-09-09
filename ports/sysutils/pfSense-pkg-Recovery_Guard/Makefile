PORTNAME=	pfSense-pkg-Recovery_Guard
PORTVERSION=	0.1.0.a1
CATEGORIES=	sysutils
MASTER_SITES=	# empty
DISTFILES=	# empty
EXTRACT_ONLY=	# empty

MAINTAINER=	github@freezweb.de
COMMENT=	Independent pfSense recovery supervisor (development preview)

LICENSE=	BSD2CLAUSE
LICENSE_FILE=	${FILESDIR}/LICENSE

NO_BUILD=	yes
NO_MTREE=	yes
SUB_FILES=	pkg-install pkg-deinstall
SUB_LIST=	PORTNAME=${PORTNAME}

do-extract:
	${MKDIR} ${WRKSRC}

do-install:
	${MKDIR} ${STAGEDIR}${PREFIX}/pkg/recovery_guard ${STAGEDIR}${PREFIX}/www ${STAGEDIR}${DATADIR}
	${INSTALL_DATA} ${FILESDIR}${PREFIX}/pkg/recovery_guard/*.php ${STAGEDIR}${PREFIX}/pkg/recovery_guard/
	${INSTALL_DATA} ${FILESDIR}${PREFIX}/pkg/recovery_guard.inc ${FILESDIR}${PREFIX}/pkg/recovery_guard.xml ${STAGEDIR}${PREFIX}/pkg/
	${INSTALL_DATA} ${FILESDIR}${PREFIX}/www/services_recovery_guard.php ${STAGEDIR}${PREFIX}/www/
	${INSTALL_DATA} ${FILESDIR}${DATADIR}/info.xml ${STAGEDIR}${DATADIR}/
	@${REINPLACE_CMD} -i '' -e 's|%%PKGVERSION%%|${PKGVERSION}|' ${STAGEDIR}${DATADIR}/info.xml

.include <bsd.port.mk>
