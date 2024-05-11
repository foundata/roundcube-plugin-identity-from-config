# Roundcube Plugin: `identity_from_config` (use config file to maintain email identities)

**WARNING: NOT FOR PRODUCTION USE YET, HIGHLY EXPERIMENTAL**

A [Roundcube](https://roundcube.net/) [plugin](https://plugins.roundcube.net/) to populate and maintain user email identities automatically on each login, based on corresponding data from the plugin's config file. A major use case is to maintain identities of shared mailboxes (like `info@example.com`) in a consistent and easy way, e.g. when using a [shared IMAP namespace](https://datatracker.ietf.org/doc/html/rfc2342.html).

You can use this plugin in combination with [`identity_from_directory`](https://github.com/foundata/roundcube-plugin-identity-from-config) which uses LDAP or Active Directory to maintain email identities.


## Licensing, copyright

<!--REUSE-IgnoreStart-->
Copyright (c) 2024, foundata GmbH (https://foundata.com)

This project is licensed under the GNU General Public License v3.0 or later (SPDX-License-Identifier: `GPL-3.0-or-later`), see [`LICENSES/GPL-3.0-or-later.txt`](LICENSES/GPL-3.0-or-later.txt) for the full text.

The [`.reuse/dep5`](.reuse/dep5) file provides detailed licensing and copyright information in a human- and machine-readable format. This includes parts that may be subject to different licensing or usage terms, such as third party components. The repository conforms to the [REUSE specification](https://reuse.software/spec/), you can use [`reuse spdx`](https://reuse.readthedocs.io/en/latest/readme.html#cli) to create a [SPDX software bill of materials (SBOM)](https://en.wikipedia.org/wiki/Software_Package_Data_Exchange).
<!--REUSE-IgnoreEnd-->

[![REUSE status](https://api.reuse.software/badge/github.com/foundata/roundcube-plugin-identity-from-config)](https://api.reuse.software/info/github.com/foundata/roundcube-plugin-identity-from-config)


## Author information

This project was created and is maintained by [foundata](https://foundata.com/). If you like it, you might [buy them a coffee](https://buy-me-a.coffee/roundcube-plugin-identity-from-config/).