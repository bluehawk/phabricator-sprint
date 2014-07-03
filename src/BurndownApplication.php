<?php

final class BurndownApplication extends PhabricatorApplication {

  public function getName() {
    return pht('Burndown Extensions');
  }

  public function getBaseURI() {
    return '/burn/';
  }


  public function getRoutes() {
    return array(
      '/burn/' => array(
        'burndown/(?P<id>\d+)/' => 'BurndownController',
      ),
    );
  }

}
