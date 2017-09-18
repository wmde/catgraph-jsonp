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
import time
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
        cursorclass=MySQLdb.cursors.DictCursor,
        use_unicode=False )
    cursor= conn.cursor()
    cursor.execute("use %s" % (wiki if wiki=='gptest1wiki' else wiki + '_p'))
    title_underscores= title.encode('utf-8').replace(' ', '_')
    for t in [ [title_underscores, "page_title"], [title_underscores.capitalize(), "page_title"], [title_underscores.lower(), "lower(page_title)"] ]:
        cursor.execute( "select page_id from page where " + t[1] + "=%s and page_namespace=14", (t[0]) )
        res= cursor.fetchall()
        if len(res):
            return str(res[0]['page_id'])
    raise RuntimeError("Category '%s' not found in wiki '%s'" % (title, wiki))

# translate 'Category:Foobar' in querystring to page_id of Foobar.
def translate_querystring(wiki, querystring):
    def translate_token(token):
        l= token.split(':')
        if len(l)==1 or l[0]!='Category': 
            return str(token)
        category_title= token[ (token.find(':')+1) : ]
        return str(category_title_to_pageid(wiki, category_title))
    
    return " ".join( [ translate_token(t) for t in querystring.split() ] )

@app.route('/catgraph-jsonp')
@app.route('/')
def helppage():
    return flask.Response(
        """<html><head><title>CatGraph JSONP Interface</title></head>
        <body><h4>CatGraph JSONP Interface</h4>
        Please see <a href="//github.com/wmde/catgraph-jsonp">this page</a> for information about this tool.</body></html>""", 
        mimetype="text/html")

# make connection and cursor for logging. create log db and table if they don't exist.
def mklogcursor(config):
    if hasattr(app, 'logcursor'): app.logcursor.close()
    if hasattr(app, 'logconn'): app.logconn.close()
    app.logconn= MySQLdb.connect( read_default_file=os.path.expanduser(config['reqlog_sqldefaultsfile']), 
        host=config['reqlog_sqlhost'], 
        use_unicode=False )
    app.logcursor= app.logconn.cursor()
    try:
        app.logcursor.execute("use %s" % config['reqlog_sqldb'])
    except MySQLdb.OperationalError as ex:
        if ex[0]==1049:
            app.logcursor.execute("create database %s" % config['reqlog_sqldb'])
            app.logcursor.execute("use %s" % config['reqlog_sqldb'])
        else: 
            raise ex
    try:
        app.logcursor.execute("""create table querylog (
            timestamp varbinary(19),
            graphname varbinary(255),
            resultlength int,
            truncated varbinary(16),
            requestargs varbinary(255)
        )""", ())
    except MySQLdb.OperationalError as ex:
        if ex[0]!=1050: # table already exists
            raise ex

# prepare connection for logging
def checklogconn(config):
    if not 'reqlog_sqldb' in config: return False
    try: app.logcursor
    except AttributeError: mklogcursor(config)
    try:
        app.logconn.ping()
    except MySQLdb.OperationalError as ex:
        if ex[0]==2006: # mysql server has gone away
            mklogcursor(config)
        else:
            raise
    return True

def MakeLogTimestamp(unixtime= None):
    if unixtime==None: unixtime= time.time()
    return time.strftime("%F %T", time.gmtime(unixtime))

def logquery(config, graphname, querystring, resultlen, reqargs):
    if not checklogconn(config): return
    truncated= "unknown"
    query= querystring.split()
    if resultlen > config["maxresultrows"]:
        truncated= "true"
    elif len(query) and query[0]=='traverse-successors':
        if len(query)==4:
            if resultlen==int(query[3]):
                truncated= "true"
            else:
                truncated= "false"
    app.logcursor.execute("insert into querylog (timestamp, graphname, resultlength, truncated, requestargs) values (%s, %s, %s, %s, %s)", 
        (MakeLogTimestamp(), graphname, resultlen, truncated, json.dumps(reqargs.to_dict())))
    app.logconn.commit()

@app.route('/catgraph-jsonp/logrequestlength')
@app.route('/logrequestlength')
def logrequestlength():
    logquery(json.load(open("config.json")), None, '', None, flask.request.args)
    params= { 'status': 'OK request logged' }
    callback= flask.request.args.get('callback', 'callback')
    return flask.Response("%s ( %s );" % (callback, json.dumps(params)), mimetype="application/javascript")

@app.route('/catgraph-jsonp/<graphname>/<path:querystring>')
@app.route('/<graphname>/<path:querystring>')
def catgraph_jsonp(graphname, querystring):
    callback= flask.request.args.get("callback", "callback")
    userparam= flask.request.args.get("userparam", None)
    def makeResponseBody(params):
        return "%s ( %s );" % (callback, json.dumps(params))
    def makeJSONPResponse(params):
        params['userparam']= userparam;
        return flask.Response(makeResponseBody(params), mimetype="application/javascript")

    try:
        config= json.load(open("config.json"))
        hostmap= requests.get(config["hostmap"]).json()
        if not graphname in hostmap:
            return makeJSONPResponse( { 'status': 'FAILED', 'statusMessage': u"Graph not found" } )
        
        gp= client.Connection(client.ClientTransport(hostmap[graphname]))
        gp.connect()
        gp.use_graph(str(graphname))

        sink= client.ArraySink()
        gp.execute(translate_querystring(graphname.split('_')[0], querystring), sink=sink)
        
        result= sink.getData()
        response= makeJSONPResponse( { 'status': gp.getStatus(), 'statusMessage': gp.getStatusMessage(), 'result': result[:config["maxresultrows"]] } )
        logquery(config, graphname, querystring, len(result), flask.request.args)
        return response
    
    except client.gpProcessorException as ex:
        # just pass on the graph processor error string
        return makeJSONPResponse( { 'status': gp.getStatus(), 'statusMessage': gp.getStatusMessage() } )
    
    except client.gpException as ex:
        # just pass on the graph processor error string
        return makeJSONPResponse( { 'status': 'FAILED', 'statusMessage': 'Exception: ' + str(ex) } )
    
    except RuntimeError as ex:
        # pass on exception string
        return makeJSONPResponse( { 'status': 'FAILED', 'statusMessage': u"RuntimeError: " + unicode(ex) } )

    except requests.ConnectionError as ex:
        # pass on exception string
        return makeJSONPResponse( { 'status': 'FAILED', 'statusMessage': u"ConnectionError: " + unicode(ex) } )

if __name__ == '__main__':
    import cgitb
    cgitb.enable()
    app.config['DEBUG']= True
    app.debug= True
    app.use_debugger= True
    WSGIServer(app).run()
