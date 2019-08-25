# Copyright 2019 Oliver Smith
# SPDX-License-Identifier: AGPL-3.0-or-later

import bpo.db
import bpo.helpers.job
import logging
import shlex


def run(arch, pkgname, branch):
    """ Start a single package build job. """
    # Change status to building
    session = bpo.db.session()
    package = bpo.db.get_package(session, pkgname, arch, branch)
    package.status = bpo.db.PackageStatus.building
    session.merge(package)
    session.commit()

    # Start job
    bpo.helpers.job.run("build_package", {
        # FIXME: use proper --mirror-pmOS parameters etc.
        # FIXME: checkout branch
        "pmbootstrap build": """
            ./pmbootstrap.py build \
                --no-depends \
                --strict \
                --arch """ + shlex.quote(arch) + """ \
                """ + shlex.quote(pkgname) + """
            """,
        "submit": """
            export BPO_API_ENDPOINT="build-package"
            export BPO_ARCH=""" + shlex.quote(arch) + """
            export BPO_BRANCH=""" + shlex.quote(branch) + """
            export BPO_PAYLOAD_FILES="$(ls -1 "$(./pmbootstrap.py -q config work)/packages/$BPO_ARCH/"*.apk)"
            export BPO_PAYLOAD_IS_JSON="0"
            export BPO_PKGNAME=""" + shlex.quote(pkgname) + """
            export BPO_PUSH_ID=""
            export BPO_VERSION=""" + shlex.quote(package.version) + """

            pmaports/.build.postmarketos.org/submit.py
            """
    })

    # FIXME: write job id back to Packages


def abort(package):
    """ Stop a single package build job.
        :param package: bpo.db.Package object """
    # FIXME
    logging.info("STUB")
