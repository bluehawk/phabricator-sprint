<?php

final class BurndownController extends PhabricatorController {

  // Project data
  private $projectID;
  private $project;

  // Start and end date for the sprint
  private $startdate;
  private $enddate;

  // Tasks and transactions
  private $tasks;
  private $xactions;

  public function willProcessRequest(array $data) {
    $this->projectID = $data['id'];
  }

  public function processRequest() {

    $request = $this->getRequest();
    $viewer = $request->getUser();

    // Load the project we're looking at, based on the project ID in the URL.
    $project = id(new PhabricatorProjectQuery())
      ->setViewer($viewer)
      ->withIDs(array($this->projectID))
      ->executeOne();
    if (!$project) {
      return new Aphront404Response();
    }

    $error_box = false;
    $burndown_chart = false;
    $burndown_table = false;
    $tasks_table = false;
    $events_table = false;

    try {
      $data = new BurndownData($project, $viewer);

      $burndown_chart = $data->buildBurnDownChart();
      $burndown_table = $data->buildBurnDownTable();
      $tasks_table    = $data->buildTasksTable();
      $events_table   = $data->buildEventTable();
    } catch (BurndownException $e) {
      $error_box = id(new AphrontErrorView())
        ->setTitle(pht('Burndown could not be rendered for this project'))
        ->setErrors(array($e->getMessage()));
    }

    $crumbs = $this->buildApplicationCrumbs();
    $crumbs->addTextCrumb(
      $project->getName(),
      '/project/view/'.$project->getID());
    $crumbs->addTextCrumb(pht('Burndown'));

    return $this->buildApplicationPage(
      array(
        $crumbs,
        $error_box,
        $burndown_chart,
        $burndown_table,
        $tasks_table,
        $events_table,
      ),
      array(
        'title' => array(pht('Burndown'), $project->getName()),
        'device' => true,
      ));
  }

}
