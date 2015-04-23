#!/usr/bin/python
# -*- coding:utf-8 -*-
# catgraph-jsonp: JSONP interface for CatGraph
# Copyright (C) Wikimedia Deutschland e.V.
# Authors: Johannes Kroll
#
# This program is free software: you can redistribute it and/or modify
# it under the terms of the GNU General Public License as published by
# the Free Software Foundation, either version 3 of the License, or
# (at your option) any later version.
#
# This program is distributed in the hope that it will be useful,
# but WITHOUT ANY WARRANTY; without even the implied warranty of
# MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
# GNU General Public License for more details.
#
# You should have received a copy of the GNU General Public License
# along with this program.  If not, see <http://www.gnu.org/licenses/>.

import os, sys
import socket
import requests
import MySQLdb
import MySQLdb.cursors
import flask
import json
from flask import Flask
from flup.server.fcgi import WSGIServer
from gp import *

app= Flask(__name__)
myhostname= socket.gethostname()

# translate a category title to a page_id
# todo: cache this.
def category_title_to_pageid(wiki, title):
    conn= MySQLdb.connect( read_default_file=os.path.expanduser('~/replica.my.cnf'), 
        host='gptest1.eqiad.wmflabs' if wiki=='gptest1wiki' else '%s.labsdb' % wiki, 
        cursorclass=MySQLdb.cursors.DictCursor )
    cursor= conn.cursor()
    cursor.execute("use %s" % (wiki if wiki=='gptest1wiki' else wiki + '_p'))
    cursor.execute("select page_id from page where page_title=%s and page_namespace=14", ( title.replace(' ', '_') ))
    return str(cursor.fetchall()[0]['page_id'])

# translate 'Category:Foobar' in querystring to page_id of Foobar.
def translate_querystring(wiki, querystring):
    def translate_token(token):
        l= token.split(':')
        if len(l)==1 or l[0]!='Category': 
            return token
        return category_title_to_pageid(wiki, l[1])
    
    return " ".join( [ translate_token(str(t)) for t in querystring.split() ] )

@app.route('/catgraph-jsonp/<graphname>/<querystring>')
@app.route('/<graphname>/<querystring>')
def cgstat(graphname, querystring):
    config= json.load(open("config.json"))
    hostmap= requests.get(config["hostmap"]).json()
    if not graphname in hostmap:
        return flask.Response("Graph not found", status="404 Graph not found", mimetype="text/plain")
    callback= flask.request.args.get("callback", "callback")
    
    gp= client.Connection(client.ClientTransport(hostmap[graphname]))
    gp.connect()
    gp.use_graph(str(graphname))

    sink= client.ArraySink()
    gp.execute(translate_querystring(str(graphname).split('_')[0], str(querystring)), sink=sink)
    
    cbparams= ", ".join( 
        [
            "[" + ",".join( [str(v) for v in row] ) + "]" 
                for row in sink.getData()[:config["maxresultrows"]]
        ] 
    )
    js= "%s( [ %s ] );" % (callback, cbparams)
    response= flask.Response(js, mimetype="application/javascript")
    return response


if __name__ == '__main__':
    import cgitb
    cgitb.enable()
    app.config['DEBUG']= True
    app.debug= True
    app.use_debugger= True
    sys.stderr.write("__MAIN__\n")
    WSGIServer(app).run()
