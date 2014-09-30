<?php
/**
 * Copyright (C) 2014 Michael Peters
 * Licensed under GNU GPL v3. See LICENSE for full details
 */

final class BurndownApplication extends PhabricatorApplication {

  public function getName() {
    return pht('Burndown Extensions');
  }

  public function getBaseURI() {
    return '/burndown/list/';
  }

  public function getIconName() {
    return 'slowvote';
  }

  public function getShortDescription() {
    return 'Build burndowns';
  }

  public function getEventListeners() {
    return array(
      new BurndownActionMenuEventListener()
    );
  }

  public function getRoutes() {
    return array(
      '/burndown/' => array(
        'list/' => 'BurndownListController',
        'view/(?P<id>\d+)/' => 'BurndownController',
      ),
    );
  }

}
