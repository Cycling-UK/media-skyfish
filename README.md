INTRODUCTION
------------
This project provides <a href="https://www.skyfish.com">Skyfish</a> image library integration.

It allows you to use any image uploaded to Skyfish via Drupal entity browsers.

Chosen image form image browser are stored locally, that it could have full functionality as local images 
(styles, attributes and etc), and is automatically mapped to field or added to textarea.

Site administrator can provide global api key and secret key, which will be used for all users if they
won't provide their own key and secret.


REQUIREMENTS
------------
This module requires the following module:
 * Entity browser (https://www.drupal.org/project/entity_browser).
 * [?] Chaos tool suite (https://www.drupal.org/project/ctools).


 INSTALLATION
------------
 * Download external library and place it in `libraries/contrib/simplepagination` location.
 * Install as you would normally install a contributed Drupal module.


CONFIGURATION
-------------
 * Global key can be added at <strong>/admin/config/media/media_skyfish</strong> 
 * by users who has a "Configure Global Media Skyfish settings" permission.
 * All users which has a "Configure own Media Skyfish settings" permission can add their keys at <strong>admin/config/media/media_skyfish/user_settings</strong>
 -----------
1. Create entity browser at <strong>admin/config/content/entity_browser</strong> with selected widget - Skyfish
2. Add Media field(s)
3. Change form display widget to <strong>Entity browser</strong> on widget settings
4. Select and save created entity browser with Skyfish widget
 

MAINTAINERS
-----------
Re-worked module for Cycling UK maintainers:
 * Anthony Cartmell (fonant) <ajcartmell@fonant.com>

Original module maintainers:
 * Andrius P. (andriuzss) - https://www.drupal.org/u/andriuzss
 * Edgaras D. (edgarasda) - https://www.drupal.org/u/edgarasda
 * Irmantas P. (irmantasp) - https://www.drupal.org/u/irmantasp

This project has been sponsored by:
 * Adapt A/S - https://www.drupal.org/node/1897408
