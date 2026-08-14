# PHP Network Weathermap (v1.8.0)

This is the **PHP Network Weathermap** plugin fork for Cacti, updated to **v1.8.0**. It builds upon the official Cacti `plugin_weathermap` codebase and introduces a **modern drag-and-drop interactive editor**, improved link routing, relative coordinate synchronization, locked node support, and a containerized Cacti 1.2.31 test environment.

The original PHP Network Weathermap was created by Howard Jones (`howie@thingy.com`) and subsequently maintained by The Cacti Group.

* See the `docs/` sub-directory for complete HTML documentation, FAQ, and configuration examples.
* See [`CHANGELOG.md`](CHANGELOG.md) for detailed version release history.
* See [`COPYING`](COPYING) for the GPL license terms.

---

## What's New in v1.8.0

* **Modern Drag-and-Drop Visual Editor**: Real-time visual manipulation of map nodes with dynamic coordinate tracking and live link recalculations.
* **Locked Node Support**: Visual locking indicators and edit safeguards for nodes imported via map `include` files.
* **Relative Position Synchronization**: Coordinated movement of parent and child nodes during drag operations.
* **Cacti 1.2.31 Docker Development Stack**: Integrated test harness in [`dev/docker-cacti-1.2.31/`](dev/docker-cacti-1.2.31/) with pinned multi-platform images and idempotent setup.

---

## Compatibility

* **Cacti**: 1.2.x onwards (tested and verified with Cacti 1.2.31 and MariaDB 11.4).
* **PHP**: PHP 8.1, 8.2, 8.3, and 8.4.
* **Browsers**: Modern evergreen browsers (Chrome, Firefox, Safari, Edge).

---

## Installation & Deployment

### 1. Download or Clone into Cacti Plugins Directory

Place the plugin in `<cacti_root>/plugins/weathermap`:

```bash
cd /var/www/html/cacti/plugins/

# Option A: Download release tarball
wget https://github.com/jakeypiez/plugin_weathermap/releases/download/v1.8.0/weathermap-v1.8.0.tar.gz
tar -xzf weathermap-v1.8.0.tar.gz
rm weathermap-v1.8.0.tar.gz

# Option B: Clone via Git
git clone -b v1.8.0 https://github.com/jakeypiez/plugin_weathermap.git weathermap
```

### 2. Set Permissions

Ensure the web server user (e.g. `www-data` or `apache`) has ownership and write access to `configs` and `output`:

```bash
chown -R www-data:www-data /var/www/html/cacti/plugins/weathermap
chmod 775 /var/www/html/cacti/plugins/weathermap/configs
chmod 775 /var/www/html/cacti/plugins/weathermap/output
```

### 3. Enable in Cacti

1. Log into your Cacti installation as an administrator.
2. Navigate to **Configuration -> Plugin Management**.
3. Locate **weathermap**, click **Install** (or **Upgrade**), then click **Enable**.

---

## Important Notes

* **Security**: The Weathermap Editor is integrated with the Cacti user management and permission realm system.
* **Images and Backgrounds**: Uploaded map icons and backgrounds are managed through the Cacti interface.
* **Overlib Dependency**: Overlib is deprecated and removed in favor of standard modern tooltip and UI frameworks.

## GitHub Documentation

Get involved in creating and editing Cacti Documentation!  Fork, change and
submit a pull request to help improve the documentation on
[GitHub](https://github.com/cacti/documentation).

## GitHub Development

Get involved in development of Cacti! Join the developers and community on
[GitHub](https://github.com/cacti)!

## Original Weathermap Plugin

Howard Jones original work can still be found on GitHub at the following location.

https://github.com/howardjones/network-weathermap

Howie has done extensive rework of his Weathermap API that we will look to incorporate
in future releases of the Cacti version of the plugin.

## Included 3rd Party Component Software

* ddSlick - A forked and jQueryUI compatible version of the jquery images dropdown
  plugin.

  See: https://jquery-plugins.net/ddslick-dropdown-with-images

* Network-Icons-SVG - A collection of network icons in SVG format converted
  to work with Weathermaps PNG format.

  See: https://github.com/aci686/Network-Icons-SVG

* The Bitstream Vera Open Source fonts (Vera\*.ttf) are copyright Bitstream, Inc.

  See: http://www.bitstream.com/font_rendering/products/dev_fonts/vera.html

* The manual uses the Kube CSS Framework and ParaType's PT Sans font.

  See: http://imperavi.com/kube/
  See: http://www.fontsquirrel.com/fonts/PT-Sans

* Some of the icons used in the editor, and also supplied in the images/ folder are
  from the excellent Fam Fam Fam Silk Icon collection by Mark James released under
  a Creative Commons license.

  See: http://www.famfamfam.com/lab/icons/silk/.
  See: http://creativecommons.org/licenses/by/2.5/

-----------------------------------------------------------------------------
Copyright (c) 2004-2026 - The Cacti Group, Inc.
