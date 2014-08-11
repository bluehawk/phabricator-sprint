<?php

final class BurndownApplication extends PhabricatorApplication {

  public function getName() {
    return pht('Burndown Extensions');
  }

  public function getBaseURI() {
    return '/burndown/';
  }

  public function getIconName() {
    return 'slowvote';
  }

  public function getShortDescription() {
    return 'Build burndowns';
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
