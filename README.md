# Neucore Mumble plugin

This package provides a solution for managing access to a Mumble server using Neucore groups.

## Requirements

- A [Neucore](https://github.com/bravecollective/neucore) installation.
- Its own Mysql/MariaDB database.
- Python 3.8
- Mumble Server (murmur)

## Install the plugin

See [Neucore Plugins.md](https://github.com/tkhamez/neucore/blob/main/doc/Plugins.md) for general installation 
instructions.

- Create the database tables by importing create.sql.

The plugin needs the following environment variables:
- NEUCORE_PLUGIN_MUMBLE_DB_DSN=mysql:dbname=brave-mumble-sso;host=127.0.0.1
- NEUCORE_PLUGIN_MUMBLE_DB_USERNAME=username
- NEUCORE_PLUGIN_MUMBLE_DB_PASSWORD=password
- NEUCORE_PLUGIN_MUMBLE_BANNED_GROUP=18 # Optional Neucore group ID, members of this group will not be able to connect.

Create a new service on Neucore for this plugin, add the "groups to tags" configuration to the "Configuration Data"
text area, example:
```
alliance.diplo: Diplo
alliance.mil.fc.full: Full FC
```

Install for development:
```shell
composer install
```

## Install Mumble

Debian/Ubuntu:

- Optional: `sudo add-apt-repository ppa:mumble/release`
- `sudo apt install mumble-server libqt5sql5-mysql`
- Edit `/etc/mumble-server.ini`
  ```
  database=mumble
  
  dbDriver=QMYSQL
  dbUsername=mumble_user
  dbPassword=password
  dbHost=127.0.0.1
  
  ice="tcp -h 127.0.0.1 -p 6502"
  
  serverpassword=a-password
  
  ... other settings that you wish to change
  ```

## Install the authenticator

Ubuntu 20.04:

- Setup:
  - `sudo apt install python3-venv python3-dev build-essential libmysqlclient-dev libbz2-dev`
  - `cd /var/www/mumble-sso/authenticator/`
  - `python3 -m venv .venv`
  - `source .venv/bin/activate`
  - `pip install wheel`
  - `pip install zeroc-ice mysqlclient`
  - `deactivate`
- Edit `authenticator/mumble-sso-auth.ini` (copy from mumble-sso-auth.ini.dist)
- Systemd service:
  - Copy the file `authenticator/mumble-authenticator.service` to 
    `/etc/systemd/system/mumble-authenticator.service` and adjust user and paths in it if needed.
  - `sudo systemctl daemon-reload`
  - `sudo systemctl enable mumble-authenticator`
  - `sudo systemctl start mumble-authenticator`
