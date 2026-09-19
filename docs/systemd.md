# systemd

Templates only. Fuzeo Queue does not install units.

`fuzeo-queue-worker.service`:

```ini
[Unit]
Description=Fuzeo Queue worker
After=network.target mysql.service redis.service

[Service]
Type=simple
User=www-data
WorkingDirectory=/var/www/html
Environment=FUZEO_QUEUE_DEPLOYMENT_ID=20260919-abc123
ExecStart=/usr/local/bin/wp fuzeo-queue work --queue=default
Restart=always
RestartSec=2
KillSignal=SIGTERM
TimeoutStopSec=90

[Install]
WantedBy=multi-user.target
```

`fuzeo-queue-scheduler.service`:

```ini
[Unit]
Description=Fuzeo Queue scheduler
After=network.target mysql.service redis.service

[Service]
Type=simple
User=www-data
WorkingDirectory=/var/www/html
Environment=FUZEO_QUEUE_DEPLOYMENT_ID=20260919-abc123
ExecStart=/usr/local/bin/wp fuzeo-queue schedule-work
Restart=on-failure
RestartSec=2
KillSignal=SIGTERM
TimeoutStopSec=60

[Install]
WantedBy=multi-user.target
```

`TimeoutStopSec` is the shutdown grace period. Queue finishes the current job on SIGTERM.
