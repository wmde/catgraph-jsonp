#!/usr/bin/python
# -*- coding:utf-8 -*-
import os
import sys
import time
import datetime
#~ import smtplib
import mimetypes
from optparse import OptionParser
from email import encoders
from email.message import Message
from email.mime.multipart import MIMEMultipart
from email.mime.text import MIMEText
import MySQLdb
import MySQLdb.cursors
import csv
import json

# print email with csv attachment to be piped to '/usr/sbin/exim -odf -i [recipients]'
def print_report(sender, recipients, month, csv):
    outer = MIMEMultipart()
    outer['Subject'] = 'catgpraph-jsonp report for %s' % month
    outer['To'] = ', '.join(recipients)
    outer['From'] = sender
    outer.preamble = 'You will not see this in a MIME-aware mail reader.\n'
    attachment = MIMEText(csv, _subtype='csv')
    attachment.add_header('Content-Disposition', 'attachment', filename='catgraph-jsonp-%s.csv' % month)
    outer.attach(attachment)
    composed = outer.as_string()
    print composed
    #~ s = smtplib.SMTP('localhost')
    #~ s.sendmail(sender, recipients, composed)
    #~ s.quit()

def makecsv(datestr):
    config= json.load(open(os.path.expanduser("~/public_html/config.json")))
    conn= MySQLdb.connect( read_default_file=os.path.expanduser(config['reqlog_sqldefaultsfile']), 
        host=config['reqlog_sqlhost'], 
        use_unicode=False,
        cursorclass=MySQLdb.cursors.DictCursor )
    cursor= conn.cursor()
    cursor.execute("use %s" % config['reqlog_sqldb'])
    # find all graph names with log entries
    cursor.execute("select graphname from querylog where graphname is not null group by graphname")
    graphnames= [ row["graphname"] for row in cursor.fetchall() ]
    fieldnames= [ "date" ] + graphnames + [ "sum" ]
    
    filename= "report-%s.csv" % datestr
    with open(filename, "w") as f:
        writer= csv.DictWriter(f, fieldnames=fieldnames)
        writer.writeheader()
        
        cursor.execute("""select DATE_FORMAT(timestamp, '%Y-%m-%d') as date, graphname, count(*) from querylog 
            where timestamp like '""" + datestr + """%' and graphname is not null and resultlength is not null and requestargs like '%callback": "jQuery%' 
            group by DATE_FORMAT(timestamp, '%Y-%m-%d'), graphname""")
            
        
        hits= dict()

        d= time.mktime( time.strptime(datestr, "%Y-%m") )
        while datetime.date.fromtimestamp(d).strftime('%Y-%m')==datestr:
            hits[datetime.date.fromtimestamp(d).strftime('%Y-%m-%d')]= { key: 0 for key in graphnames }
            d+= 60*60*24
        
        for row in cursor.fetchall():
            if not row["date"] in hits:
                hits[row["date"]]= { key: 0 for key in graphnames }
            
            hits[row["date"]][row["graphname"]]= row["count(*)"]
                    
        for date in sorted(hits):
            csvrow= hits[date]
            sum= 0
            for graph in csvrow:
                sum+= csvrow[graph]
            csvrow["sum"]= sum
            csvrow["date"]= date
            writer.writerow(csvrow)
    return open(filename).read()

if __name__=='__main__':
    #~ parser = OptionParser(usage="""\
#~ XXX TODO FILLIN""")
    #~ parser.add_option('-d', '--directory',
                      #~ type='string', action='store',
                      #~ help="""Mail the contents of the specified directory,
                      #~ otherwise use the current directory.  Only the regular
                      #~ files in the directory are sent, and we don't recurse to
                      #~ subdirectories.""")
    #~ parser.add_option('-o', '--output',
                      #~ type='string', action='store', metavar='FILE',
                      #~ help="""Print the composed message to FILE instead of
                      #~ sending the message to the SMTP server.""")
    #~ parser.add_option('-s', '--sender',
                      #~ type='string', action='store', metavar='SENDER',
                      #~ help='The value of the From: header (required)')
    #~ parser.add_option('-r', '--recipient',
                      #~ type='string', action='append', metavar='RECIPIENT',
                      #~ default=[], dest='recipients',
                      #~ help='A To: header value (at least one required)')
    #~ opts, args= parser.parse_args()
    
    last_month= datetime.date.fromtimestamp(time.time() - 1*60*60*24).strftime('%Y-%m')
    print_report('catgraph-jsonp@tools.wmflabs.org', ['johannes.kroll@wikimedia.de'], last_month, makecsv(last_month))