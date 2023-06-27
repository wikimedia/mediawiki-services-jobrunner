redisJobRunnerService -- Continuously process a MediaWiki jobqueue

Installation
-----------

```$ composer install --no-dev```

Description
-----------

redisJobRunnerService is an infinite "while loop" used to call MediaWiki runJobs.php
and eventually attempt to process any job enqueued. A number of virtual sub-loops with
their own runners and job types must be defined via parameters. These loops can set any
of the job types as either low or high priority. High priority will get ~80% of the time
share of the sub-loop under "busy" conditions. If there are few high priority jobs, the
loops will spend much more of their time on the low priority ones.

The runner must be started with a config file location specified.
An annotated example config file is provided in jobrunner.sample.json.
You will probably want to run this script under your webserver username.
The runner can be made into a service via upstart (or anything comparable).

Example:
```
redisJobRunnerService --config-file=/etc/jobrunner/jobrunner.json --verbose
```

License
-------
Copyright 2014- MediaWiki contributors

This program is free software; you can redistribute it and/or modify
it under the terms of the GNU General Public License as published by
the Free Software Foundation; either version 2 of the License, or
(at your option) any later version.

This program is distributed in the hope that it will be useful,
but WITHOUT ANY WARRANTY; without even the implied warranty of
MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE. See the
GNU General Public License for more details.

You should have received a copy of the GNU General Public License along
with this program; if not, write to the Free Software Foundation, Inc.,
51 Franklin Street, Fifth Floor, Boston, MA 02110-1301, USA.
<http://www.gnu.org/copyleft/gpl.html>
