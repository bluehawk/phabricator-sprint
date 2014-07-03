<?php

final class BurndownController extends PhabricatorController {

  private $projectID;

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

    // Load the data for the chart. This approach tries to be simple, but loads
    // and processes large amounts of unnecessary data, so it is not especially
    // fast. Some performance improvements can be made at the cost of fragility
    // by using raw SQL; real improvements can be made once Facts comes online.

    // First, load *every task* in the project. We have to do something like
    // this because there's no straightforward way to determine which tasks
    // have activity in the project period.
    $tasks = id(new ManiphestTaskQuery())
      ->setViewer($viewer)
      ->withAnyProjects(array($project->getPHID()))
      ->execute();

    // Now load *every transaction* for those tasks. This loads all the
    // comments, etc., for every one of the tasks. Again, not very fast, but
    // we largely do not have ways to select this data more narrowly yet.
    if ($tasks) {
      $task_phids = mpull($tasks, 'getPHID');

      $xactions = id(new ManiphestTransactionQuery())
        ->setViewer($viewer)
        ->withObjectPHIDs($task_phids)
        ->execute();
    }

    // Examine all the transactions and extract "events" out of them. These are
    // times when a task was opened or closed. Make some effort to also track
    // "scope" events (when a task was added or removed from a project).
    $scope_phids = array($project->getPHID());
    $events = $this->extractEvents($xactions, $scope_phids);


    // TODO: Render an actual chart. For now, I'm rendering a table with the
    // data in it instead.

    $xactions = mpull($xactions, null, 'getPHID');
    $tasks = mpull($tasks, null, 'getPHID');

    $rows = array();
    foreach ($events as $event) {
      $task_phid = $xactions[$event['transactionPHID']]->getObjectPHID();
      $task = $tasks[$task_phid];

      $rows[] = array(
        phabricator_datetime($event['epoch'], $viewer),
        $event['type'],
        phutil_tag(
          'a',
          array(
            'href' => '/'.$task->getMonogram(),
          ),
          $task->getMonogram().': '.$task->getTitle()),
      );
    }

    $table = id(new AphrontTableView($rows))
      ->setHeaders(
        array(
          pht('When'),
          pht('Type'),
          pht('Task'),
        ))
      ->setColumnClasses(
        array(
          '',
          '',
          'wide',
        ));

    $box = id(new PHUIObjectBoxView())
      ->setHeaderText(pht('Raw Data for Eventual Chart'))
      ->appendChild($table);

    $crumbs = $this->buildApplicationCrumbs();
    $crumbs->addTextCrumb(
      $project->getName(),
      '/project/view/'.$project->getID());
    $crumbs->addTextCrumb(pht('Burndown'));

    return $this->buildApplicationPage(
      array(
        $crumbs,
        $box,
      ),
      array(
        'title' => array(pht('Burndown'), $project->getName()),
        'device' => true,
      ));
  }


  /**
   * Extract important events (the times when tasks were opened or closed)
   * from a list of transactions.
   *
   * @param list<ManiphestTransaction> List of transactions.
   * @param list<phid> List of project PHIDs to emit "scope" events for.
   * @return list<dict> Chronologically sorted events.
   */
  private function extractEvents(array $xactions, array $scope_phids) {
    assert_instances_of($xactions, 'ManiphestTransaction');

    $scope_phids = array_fuse($scope_phids);

    $events = array();
    foreach ($xactions as $xaction) {
      $old = $xaction->getOldValue();
      $new = $xaction->getNewValue();

      $event_type = null;
      switch ($xaction->getTransactionType()) {
        case ManiphestTransaction::TYPE_STATUS:
          $old_is_closed = ($old === null) ||
                           ManiphestTaskStatus::isClosedStatus($old);
          $new_is_closed = ManiphestTaskStatus::isClosedStatus($new);

          if ($old_is_closed == $new_is_closed) {
            // This was just a status change from one open status to another,
            // or from one closed status to another, so it's not an event we
            // care about.
            break;
          }

          if ($new_is_closed) {
            $event_type = 'close';
          } else {
            $event_type = 'open';
          }
          break;

        case ManiphestTransaction::TYPE_PROJECTS:
          $old = array_fuse($old);
          $new = array_fuse($new);

          $in_old_scope = array_intersect_key($scope_phids, $old);
          $in_new_scope = array_intersect_key($scope_phids, $new);

          if ($in_new_scope && !$in_old_scope) {
            $event_type = 'scope-expand';
          } else if ($in_old_scope && !$in_new_scope) {
            // NOTE: We will miss some of these events, becuase we are only
            // examining tasks that are currently in the project. If a task
            // is removed from the project and not added again later, it will
            // just vanish from the chart completely, not show up as a
            // scope contraction. We can't do better until the Facts application
            // is avialable without examining *every* task.
            $event_type = 'scope-contract';
          }
          break;

        // TODO: To find events where scope was changed by altering the number
        // of points for a task, you can examine custom field transactions,
        // which have type PhabricatorTransactions::TYPE_CUSTOMFIELD.

        default:
          // This is something else (comment, subscription change, etc) that
          // we don't care about for now.
          break;
      }

      // If we found some kind of event that we care about, stick it in the
      // list of events.
      if ($event_type !== null) {
        $events[] = array(
          'transactionPHID' => $xaction->getPHID(),
          'epoch' => $xaction->getDateCreated(),
          'type' => $event_type,
        );
      }
    }

    // Sort all events chronologically.
    $events = isort($events, 'epoch');

    return $events;
  }


}
