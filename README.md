# catgraph-jsonp
This is a JSONP interface to [Catgraph](https://wikitech.wikimedia.org/wiki/Nova_Resource:Catgraph/Documentation). It is used by the [DeepCat Gadget](https://github.com/wmde/DeepCat-Gadget).

## Usage
Do a JSONP request to http://tools.wmflabs.org/catgraph-jsonp/GRAPHNAME/COMMAND?userparam=PARAM&callback=YOURFUNCTION where COMMAND is the graphcore command to execute on GRAPHNAME. YOURFUNCTION is your JSONP callback. It will be called with a dict with the following fields:
* 'status' is the [graphserv response](https://github.com/wmde/graphcore/blob/master/spec.rst#responses)
* 'statusMessage' contains the rest of the graphserv status message if present
* 'result' contains any graphcore result rows converted to arrays
* 'userparam' reflects the corresponding cgi parameter (optional).

Example: Get the root categories (categories without a parent category) of frwiki. http://tools.wmflabs.org/catgraph-jsonp/frwiki_ns14/list-roots?callback=dostuff

http://tools.wmflabs.org/catgraph-jsonp is configured to return at most 500 result rows, larger results are truncated.

## Issue tracker
Please file bugs and feature requests on [Phabricator] (https://phabricator.wikimedia.org/maniphest/task/create/?projects=tcb-team,catgraph&title=%5BCatGraph-jsonp%5D).
