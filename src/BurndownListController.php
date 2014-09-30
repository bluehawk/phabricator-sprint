<?php
/**
 * Copyright (C) 2014 Michael Peters
 * Licensed under GNU GPL v3. See LICENSE for full details
 */

final class BurndownListController extends PhabricatorController {

  public function willProcessRequest(array $data) {
  }

  public function processRequest() {
    $request = $this->getRequest();
    $viewer = $request->getUser();

    // Load all projects with "Sprint" in the name.
    $projects = id(new PhabricatorProjectQuery())
      ->setViewer($viewer)
      ->withDatasourceQuery('sprint')
      ->execute();

    $rows = array();
    foreach ($projects as $project) {
      // We need the custom fields so we can pull out the start and end date
      // TODO: query in a loop is bad
      $field_list = PhabricatorCustomField::getObjectFields(
        $project,
        PhabricatorCustomField::ROLE_EDIT);
      $field_list->setViewer($viewer);
      $field_list->readFieldsFromStorage($project);
      $aux_fields = $field_list->getFields();

      $start = idx($aux_fields, 'isdc:sprint:startdate')
        ->getProxy()->getFieldValue();
      $end = idx($aux_fields, 'isdc:sprint:enddate')
        ->getProxy()->getFieldValue();

      $rows[] = array(
        'project' => phutil_tag('a',
          array(
            'href'  => '/burndown/view/'.$project->getId(),
            'style' => 'font-weight:bold',
          ),
          $project->getName()
        ),
        'start'   => phabricator_datetime($start, $viewer),
        'end'     => phabricator_datetime($end, $viewer),
      );
    }

    $projects_table = id(new AphrontTableView($rows))
      ->setHeaders(
        array(
          'Project/Sprint name',
          'Start Date',
          'End Date',
        ))
      ->setColumnClasses(
        array(
          'wide',
          'date',
          'date',
        ));

    $crumbs = $this->buildApplicationCrumbs();
    $crumbs->addTextCrumb(pht('Burndown List'));

    $help = id(new PHUIBoxView())
      ->appendChild(phutil_tag('p', array(),
          "To have a project show up in this list, make sure it's name includes"
          ."\"sprint\" and then edit it to set the start and end date."
      ))
      ->addMargin(PHUI::MARGIN_LARGE);

    $box = id(new PHUIBoxView())
      ->appendChild($projects_table)
      ->addMargin(PHUI::MARGIN_LARGE);

    return $this->buildApplicationPage(
      array(
        $crumbs,
        $help,
        $box,
      ),
      array(
        'title' => array(pht('Burndown List')),
        'device' => true,
      ));
  }

}
