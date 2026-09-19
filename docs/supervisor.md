# Supervisor

Fuzeo Queue decides **when a process should exit**. Supervisor starts and restarts the OS process.

Do not generate these files from Queue. Copy and adjust paths, user, and environment.

```ini
[program:fuzeo-queue-default]
command=wp fuzeo-queue work --queue=default
directory=/var/www/html
user=www-data
autostart=true
autorestart=true
stopwaitsecs=90
stopsignal=TERM
stdout_logfile=/var/log/fuzeo-queue-default.log
stderr_logfile=/var/log/fuzeo-queue-default.err.log
environment=FUZEO_QUEUE_DEPLOYMENT_ID="20260919-abc123"

[program:fuzeo-queue-imports]
command=wp fuzeo-queue work --queue=imports
directory=/var/www/html
user=www-data
autostart=true
autorestart=true
stopwaitsecs=90
stopsignal=TERM
numprocs=2
process_name=%(program_name)s_%(process_num)02d

[program:fuzeo-queue-scheduler]
command=wp fuzeo-queue schedule-work
directory=/var/www/html
user=www-data
autostart=true
autorestart=true
stopwaitsecs=60
stopsignal=TERM
```

`stopwaitsecs` must exceed typical job runtime you are willing to wait on SIGTERM. Fuzeo Queue finishes the current job; it does not `kill -9`.

Separate groups for default, imports, and webhooks so concurrency stays queue-specific.
