# Copyright 2019 Oliver Smith
# SPDX-License-Identifier: AGPL-3.0-or-later

""" Database code, using sqlalchemy ORM.
    Usage example:
        session = bpo.db.session()
        log = bpo.db.Log(action="db_init", details="hello world")
        session.add(log)
        session.commit() """

import enum
import glob
import logging
import os
import sys
import json

import sqlalchemy
import sqlalchemy.orm
import sqlalchemy.ext.declarative
import sqlalchemy.sql
from sqlalchemy import Column, Integer, String, DateTime, Text, ForeignKey, \
                       Table, Boolean, Index, Enum
from sqlalchemy.orm import relationship

import bpo.config.args


base = sqlalchemy.ext.declarative.declarative_base()
session = None
init_relationships_complete = False


class Push(base):
    __tablename__ = "push"
    id = Column(Integer, primary_key=True)
    date = Column(DateTime(timezone=True),
                           server_default=sqlalchemy.sql.func.now())
    branch = Column(String)

    def __init__(self, branch):
        self.branch = branch

class Commit(base):
    __tablename__ = "commit"
    id = Column(Integer, primary_key=True)
    ref = Column(String)
    message = Column(String)
    push_id = Column(Integer, ForeignKey("push.id"))


class PackageStatus(enum.Enum):
    queued = 0
    building = 1
    built = 2
    published = 3
    failed = 4


class Package(base):
    __tablename__ = "package"
    id = Column(Integer, primary_key=True)
    date = Column(DateTime(timezone=True),
                           server_default=sqlalchemy.sql.func.now())
    last_update = Column(DateTime(timezone=True),
                         onupdate=sqlalchemy.sql.func.now())
    arch = Column(String)
    branch = Column(String)
    pkgname = Column(String)
    status = Column(Enum(PackageStatus))
    build_id = Column(Integer, unique=True)

    # The following columns represent the latest state. We don't store the
    # history in bpo (avoids complexity, we have the git history for that).
    version = Column(String)
    repo = Column(String)

    Index("pkgname-arch-branch", pkgname, arch, branch, unique=True)

    # Package.depends: see init_relationships() below.


    def __repr__(self):
        depends=[]
        for depend in self.depends:
            depends.append(depend.pkgname)
        return (str(self.arch) + "/" + str(self.repo) + "/" +
                str(self.pkgname) + "-" + str(self.version) +
                "@" + self.branch + " (pmOS depends: " + str(depends) + ")")


    def __init__(self, arch, branch, pkgname, version):
        self.arch = arch
        self.branch = branch
        self.pkgname = pkgname
        self.version = version
        self.status = PackageStatus.queued


    def depends_built(self):
        for depend in self.depends:
            if depend.status.value < PackageStatus.built.value:
                return False
        return True


class Log(base):
    __tablename__ = "log"
    id = Column(Integer, primary_key=True)
    date = Column(DateTime(timezone=True),
                           server_default=sqlalchemy.sql.func.now())
    action = Column(String)
    payload = Column(Text)
    arch = Column(String)
    branch = Column(String)
    pkgname = Column(String)
    version = Column(String)


    def __init__(self, action, payload=None, arch=None, branch=None,
                 pkgname=None, version=None):
        self.action = action
        self.payload = json.dumps(payload, indent=4) if payload else None
        self.arch = arch
        self.branch = branch
        self.pkgname = pkgname
        self.version = version


def init_relationships():
    # Only run this once!
    self = sys.modules[__name__]
    if self.init_relationships_complete:
        return
    self.init_relationships_complete = True

    # commits.push_id - n:1 - push.id
    self.Commit.push = relationship("Push", back_populates="commits")
    self.Push.commits = relationship("Commit", order_by=self.Commit.id,
                                     back_populates="push")

    # package.depends - n:n - package.required_by
    # See "Self-Referential Many-to-Many Relationship" in:
    # https://docs.sqlalchemy.org/en/13/orm/join_conditions.html
    assoc = Table("package_dependency", base.metadata,
                  Column("package_id", ForeignKey("package.id"),
                         primary_key=True),
                  Column("dependency_id", ForeignKey("package.id"),
                         primary_key=True))
    self.Package.depends = relationship("Package", secondary=assoc,
        primaryjoin=self.Package.id==assoc.c.package_id,
        secondaryjoin=self.Package.id==assoc.c.dependency_id,
        order_by=self.Package.id,
        backref="required_by")


def init():
    """ Initialize db """
    # Disable check_same_thread, so pysqlite does not print ProgrammingError
    # junk when running the tests with pytest. SQLAlchemy uses pooling to make
    # sure that a single connection is not used in more than one thread, so we
    # can safely disable this check.
    # https://docs.sqlalchemy.org/en/latest/dialects/sqlite.html
    connect_args = {"check_same_thread": False}

    # Open DB and initialize
    self = sys.modules[__name__]
    url = "sqlite:///" + bpo.config.args.db_path
    engine = sqlalchemy.create_engine(url, connect_args=connect_args)
    init_relationships()
    self.base.metadata.create_all(engine)
    self.session = sqlalchemy.orm.sessionmaker(bind=engine)


def get_package(session, pkgname, arch, branch):
    result = session.query(bpo.db.Package).filter_by(arch=arch,
                                                     branch=branch,
                                                     pkgname=pkgname).all()
    return result[0] if len(result) else None
