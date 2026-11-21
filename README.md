# Neucore Mumble plugin

_Needs [Neucore](https://github.com/tkhamez/neucore) version 2.2.0 or higher._

This package provides a solution for accessing a Mumble server using Neucore groups for permissions.

Features:
- Permissions based on Neucore groups.
- Configurable nickname based on character data and Neucore groups (tags).
- Avatars
- Bans based on Neucore groups, corporation or alliance.
- Support for multiple Mumble servers.

## How it works

The plugin uses its own database, which is populated by the Neucore service plugin and read by the Mumble 
authenticator.

### Permissions

All Neucore groups of anyone who connects to Mumble are added to the server. They are then available as groups 
in the ACL configuration.

Accounts that are members of the "banned" group (configurable ID from the plugin configuration data) cannot connect 
to Mumble.

It is also possible to ban entire corporations or alliances. To do this, manually add an entry to the ban table, e.g.:  
`INSERT INTO ban (filter, reason_public, reason_internal) VALUES ('alliance-99001861', '', '');`  
`INSERT INTO ban (filter, reason_public, reason_internal) VALUES ('corporation-605398057', '', '');`

### Names

The Mumble display name is set to the string from the "Nickname" configuration. There are the following placeholders: 
{allianceTicker}, {corporationTicker}, {characterName} (required) and {tags}. Characters that come directly before 
or after a placeholder are removed if there is no value for the placeholder. If there are characters that come 
directly before or after the {tags} placeholder, they are used around every tag.

The tags are added according to the "GroupsToTags" configuration of the service plugin. Only the first tag from 
each group is used. Every tag not included in the optional "AdditionalTagGroups" configuration is part of the main 
tag group, they are added as the last tag. The optional "AdditionalTagGroups" configuration defines 
additional groups, they are added in the order they are defined there before the main tag. The "Configuration Data"
contains an example when you add a new plugin.

The main tag can optionally be used instead of the corporation ticker at its position, configured by the 
"MainTagReplacesCorporationTicker" configuration value.

## Requirements

- A [Neucore](https://github.com/tkhamez/neucore) installation.
- Its own MySQL/MariaDB database.
- Python 3.12 or 3.13 (other versions may work but were not tested)
- Mumble server 1.5 (other versions were not tested, but it should no longer work with 1.3)

## Install the plugin

See [Neucore Plugins.md](https://github.com/tkhamez/neucore/blob/main/doc/Plugins.md#install-a-plugin) for 
general installation instructions.

- Create a new database (e.g. neucore_mumble)
- Create the database tables by importing create.sql.

The plugin needs the following environment variable:
- `NEUCORE_MUMBLE_PLUGIN_DB_DSN=mysql:host=127.0.0.1;dbname=neucore_mumble;user=mumble;password=pass`  
  The name of the environment variable can be changed with "DatabaseEnvVar" from the configuration
  data of the plugin. This makes it possible to add several Mumble services for different Mumble servers.

Optional environment variables:
- `NEUCORE_MUMBLE_PLUGIN_DB_SSL_CA="/path/to/ca-cert.pem"`  
  Setting this will enable encryption for the database connection, even if it is set to an empty value.
- `NEUCORE_MUMBLE_PLUGIN_DB_SSL_VERIFY=1`

Create a new service on Neucore for this plugin and adjust the "Configuration Data" text area and other
configuration values that you want to change.

This plugin uses the Neucore command "update-service-accounts" to update Mumble permissions etc., so make sure that
the [Neucore cronjob](https://github.com/tkhamez/neucore/blob/main/doc/Install.md#cronjob) is running.

## Install Mumble

Example for Ubuntu 24.04 with MySQL:

- `sudo apt install mumble-server libqt5sql5-mysql`
- `sudo dpkg-reconfigure mumble-server` to set the SuperUser password.
- Edit `/etc/mumble/mumble-server.ini` and adjust at least:
  ```
  database=mumble
  
  dbDriver=QMYSQL
  dbUsername=mumble_user
  dbPassword=password
  dbHost=127.0.0.1
  
  ice="tcp -h 127.0.0.1 -p 6502"
  
  serverpassword=a-password
  ```

There's a simple script included to manage virtual servers:
[authenticator/manage-server.py](authenticator/manage-server.py). It needs the same setup as the
authenticator (see below). You can run it with, e.g. `python manage-server.py 127.0.0.1 6502`.

## Install the authenticator

The authenticator has been tested with Python 3.12 and 3.13 on Ubuntu 24.04 with Mumble server 1.5.

Example for Ubuntu 24.04 with Python 3.13:

- Setup:
  - `sudo apt install python3-venv python3-dev build-essential default-libmysqlclient-dev libbz2-dev pkg-config`
  - `sudo add-apt-repository ppa:deadsnakes/ppa`
  - `sudo apt install python3.13 python3.13-venv python3.13-dev`
  - Clone repository to `/opt/neucore-mumble-plugin`
  - `cd /opt/neucore-mumble-plugin/authenticator/`
  - `python3.13 -m venv .venv`
  - `source .venv/bin/activate`
  - `pip install wheel`
  - `pip install mysqlclient zeroc-ice`
  - `deactivate`
- Create and edit `authenticator/mumble-authenticator-1.ini` (copy from `mumble-authenticator.ini.dist`). The number
  in the  filename corresponds to the number from the systemd service parameter below (`@1`).
- Systemd service:
  - Copy the file `authenticator/mumble-authenticator@.service` to 
    `/etc/systemd/system/mumble-authenticator@.service` and adjust user and paths in it if needed.
  - `sudo systemctl daemon-reload`
  - `sudo systemctl enable mumble-authenticator@1`
  - `sudo systemctl start mumble-authenticator@1`

Please note that the authenticator must be restarted after Mumble has been restarted.

If you run multiple virtual servers, you can either use the same authenticator service for them by
adding the other server IDs to `servers` in the configuration file or add a separate service. For a
separate service, create the file `mumble-authenticator-2.ini`, adjust at least `sql_name` and `servers`
and enable and start the service `mumble-authenticator@2`.

## Development

```shell
docker build --tag neucore-mumble-plugin .
docker run -it --mount type=bind,source="$(pwd)",target=/app --workdir /app neucore-mumble-plugin /bin/sh
```

## Copyright notice

This plugin is licensed under the [MIT license](LICENSE).

"EVE", "EVE Online", "CCP" and all related logos and images are trademarks or registered 
trademarks of [CCP hf](http://www.ccpgames.com/).
