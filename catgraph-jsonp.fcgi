#!/usr/bin/python
# -*- coding:utf-8 -*-
# cgstat: Web-based status app for CatGraph
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

import sys
import socket
import requests
import flask
import json
from flask import Flask
from flup.server.fcgi import WSGIServer
from gp import *

app= Flask(__name__)
myhostname= socket.gethostname()


#~ @app.route('/catgraph-jsonp/<graphname>/<querystring>')
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
    gp.execute(str(querystring), sink=sink)
    
    cbparams= ", ".join( 
        [
            "Array(" + ",".join( [str(v) for v in row] ) + ")" 
                for row in sink.getData()[:config["maxresultrows"]]
        ] 
    )
    js= "%s( Array( %s ) );" % (callback, cbparams)
    response= flask.Response(js, mimetype="application/javascript")
    #~ response.headers.add("Content-Encoding", "identity")
    return response


if __name__ == '__main__':
    import cgitb
    cgitb.enable()
    app.config['DEBUG']= True
    app.debug= True
    app.use_debugger= True
    sys.stderr.write("__MAIN__\n")
    WSGIServer(app).run()
    #~ app.run(debug=app.debug, use_debugger=app.debug)
