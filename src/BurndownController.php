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
    $this->project = id(new PhabricatorProjectQuery())
      ->setViewer($viewer)
      ->withIDs(array($this->projectID))
      ->executeOne();
    if (!$this->project) {
      return new Aphront404Response();
    }

    // We need the custom fields so we can pull out the start and end date
    $field_list = PhabricatorCustomField::getObjectFields(
      $this->project,
      PhabricatorCustomField::ROLE_EDIT);
    $field_list->setViewer($viewer);
    $field_list->readFieldsFromStorage($this->project);
    $aux_fields = $field_list->getFields();

    $this->startdate = idx($aux_fields, 'isdc:sprint:startdate')
      ->getProxy()->getFieldValue();
    $this->enddate = idx($aux_fields, 'isdc:sprint:enddate')
      ->getProxy()->getFieldValue();

    if (!$this->startdate OR !$this->enddate)
    {
      // TODO this is bad
      echo "That project is not set up for Burndowns, make sure it has "
      ."'Sprint' in the name, and then edit it to add the sprint start and "
      ."end date.";
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
      ->withAnyProjects(array($this->project->getPHID()))
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
    $scope_phids = array($this->project->getPHID());
    $events = $this->extractEvents($xactions, $scope_phids);

    $this->xactions = mpull($xactions, null, 'getPHID');
    $this->tasks = mpull($tasks, null, 'getPHID');

    $data = $this->buildBurnDownData($events, $viewer);

    $chart_box = $this->buildBurnDownChart($data);
    $table_box = $this->buildBurnDownTable($data);
    $events_box = $this->formatEventData($events, $viewer);

    $crumbs = $this->buildApplicationCrumbs();
    $crumbs->addTextCrumb(
      $this->project->getName(),
      '/project/view/'.$this->project->getID());
    $crumbs->addTextCrumb(pht('Burndown'));

    return $this->buildApplicationPage(
      array(
        $crumbs,
        $chart_box,
        $table_box,
        $events_box,
      ),
      array(
        'title' => array(pht('Burndown'), $this->project->getName()),
        'device' => true,
      ));
  }

  /**
   * The idea here is to go through the history and keep track of important
   * things, like how many points are currently assigned to each task, and what
   * changed each day. So events like "task opened" or "points changed" or
   * "task added to this project" get added to the values for the day it
   * occured on.
   *
   * After the foreach($events), each day only holds WHAT HAPPENED THAT DAY
   *
   * Then we loop through the days, and sum up the numbers from previous days
   * so the graph makes sense.
   *
   * This is all pretty ugly, and could probably be made more robust using
   * actual objects.
   */
  private function buildBurnDownData($events, $viewer)
  {
    // Build an array of dates between start and end
    $start = new DateTime("@".$this->startdate);
    $end = new DateTime("@".$this->enddate);
    $interval = new DateInterval('P1D'); // 1 day interval
    $period = new DatePeriod($start, $interval, $end);
    $template = array(
      'tasks_total'   => 0,
      'tasks_done'    => 0,
      'points_total'  => 0,
      'points_done'   => 0,
      'points_remain' => 0,
      'ideal_points'  => 0,
    );
    $dates = array('Start of Sprint' => $template);
    foreach ($period as $day) {
      $dates[$day->format('D M j')] = $template;
    }
    $dates['After end of Sprint'] = $template;

    // Build arrays to store current point and closed status of tasks as we
    // progress through time, so that these changes reflect on the graph
    $task_points = array();
    $task_closed = array();
    foreach($this->tasks as $task) {
      $task_points[$task->getPHID()] = 0;
      $task_closed[$task->getPHID()] = 0;
    }

    // Now loop through the events and build the data for each day
    foreach ($events as $event) {

      $xaction = $this->xactions[$event['transactionPHID']];
      $xaction_date = $xaction->getDateCreated();
      $task_phid = $xaction->getObjectPHID();
      $task = $this->tasks[$task_phid];

      // Determine which date to attach this data to
      if ($xaction_date < $this->startdate) {
        $date = 'Start of Sprint';
      } else if ($xaction_date > $this->enddate) {
        $date = 'End of Sprint';
      } else {
        //$date = id(new DateTime("@".$xaction_date))->format('D M j');
        $date = phabricator_format_local_time($xaction_date, $viewer, 'D M j');
      }

      switch($event['type']) {
        case "create":
          // Will be accounted for by "task-add" when the project is added
          break;
        case "task-add":
          // A task was added to the sprint
          $dates[$date]['tasks_total'] += 1;
          $dates[$date]['points_total'] += $task_points[$task_phid];
          break;
        case "task-remove":
          // A task was removed from the sprint
          $dates[$date]['tasks_total'] -= 1;
          $dates[$date]['points_total'] -= $task_points[$task_phid];
          break;
        case "close":
          // A task was closed, mark it as done
          $dates[$date]['tasks_done'] += 1;
          $dates[$date]['points_done'] += $task_points[$task_phid];
          $task_closed[$task_phid] = 1;
          break;
        case "reopen":
          // A task was reopened, subtract from done
          $dates[$date]['tasks_done'] -= 1;
          $dates[$date]['points_done'] -= $task_points[$task_phid];
          $task_closed[$task_phid] = 0;
          break;
        case "points":
          // Points were changed
          $dates[$date]['points_total'] += $xaction->getNewValue() - $xaction->getOldValue();
          $task_points[$task_phid] = $xaction->getNewValue();
          // If the task is closed, we need to adjust the completed points
          if ($task_closed[$task_phid]) {
            $dates[$date]['points_done'] += $xaction->getNewValue() - $xaction->getOldValue();
          }
          break;
      }
    }

    // Now that we have the data for each day, we need to loop over and sum
    // up everything
    $previous = null;
    foreach ($dates as $date => $data) {
      if ($previous) {
        foreach ($data as $key => $value) {
          $dates[$date][$key] += $dates[$previous][$key];
        }
      }
      $previous = $date;
    }

    // Compute "Points remaining" column
    foreach ($dates as $date => $data) {
      $dates[$date]['points_remain'] = $data['points_total']
        - $data['points_done'];
    }

    // Compute "Ideal Points remaining" column


    // Move the date from the key to being part of the array
    $out = array();
    foreach ($dates as $date => $data)
    {
      $out[] = array_merge(array($date),$data);
    }

    return $out;
  }

  private function buildBurnDownTable($data)
  {
    $table = id(new AphrontTableView($data))
      ->setHeaders(
        array(
          pht('Date'),
          pht('Total Tasks'),
          pht('Completed Tasks'),
          pht('Total Points'),
          pht('Completed Points'),
          pht('Remaining Points'),
          pht('Ideal Points'),
        ))
      ->setColumnClasses(
        array(
          '',
          '',
          '',
          '',
          '',
        ));

    $box = id(new PHUIObjectBoxView())
      ->setHeaderText(pht('DATA'))
      ->appendChild($table);

    return $box;
  }

  private function buildBurnDownChart($data)
  {
    $data_table = array(array(
      pht('Date'),
      pht('Total Tasks'),
      pht('Completed Tasks'),
      pht('Total Points'),
      pht('Completed Points'),
      pht('Remaining Points'),
      pht('Ideal Points'),
    ));
    foreach($data as $data)
    {
      $data_table[] = array_values($data);
    }
    // Format the data for the chart
    $data_table = json_encode($data_table);

    // This should probably use celerity and/or javelin
    $box = id(new PHUIObjectBoxView())
      ->setHeaderText(pht('Burndown for '.$this->project->getName()))
      // Calling phutil_safe_html and passing in <script> tags is a potential
      // security hole. None of this data is direct user input, so we should
      // be fine.
      ->appendChild(phutil_safe_html(<<<HERE
<script type="text/javascript" src="//www.google.com/jsapi"></script>
<script type="text/javascript">
  google.load('visualization', '1', {packages: ['corechart']});
</script>
<script type="text/javascript">

  function drawVisualization() {
    // Create and populate the data table.
    var data = google.visualization.arrayToDataTable($data_table);
    //   ['Month', 'Bolivia', 'Ecuador', 'Madagascar', 'Papua New Guinea', 'Rwanda', 'Average'],
    //   ['2004/05',  165,      938,         522,             998,           450,      614.6],
    //   ['2005/06',  135,      1120,        599,             1268,          288,      682],
    //   ['2006/07',  157,      1167,        587,             807,           397,      623],
    //   ['2007/08',  139,      1110,        615,             968,           215,      609.4],
    //   ['2008/09',  136,      691,         629,             1026,          366,      569.6]
    // ]);

    // Create and draw the visualization.
    var ac = new google.visualization.ComboChart(document.getElementById('visualization'));
    ac.draw(data, {
      height: 400,
      vAxis: {title: "Points"},
      hAxis: {title: "Date"},
      seriesType: "line",
      series: {5: {type: "bars"}}
    });
  }


  google.setOnLoadCallback(drawVisualization);
</script>
HERE
        ))
      ->appendChild(phutil_tag('div',
        array(
          'id' => 'visualization',
          'style' => 'width: 100%; height:400px'
        ),''));

    return $box;

  }

  /**
   * This takes the data returned by extractEvents and formats it for the
   * Events table.
   */
  private function formatEventData($events, $viewer)
  {
    foreach ($events as $event) {
      $task_phid = $this->xactions[$event['transactionPHID']]->getObjectPHID();
      $task = $this->tasks[$task_phid];

      $rows[] = array(
        phabricator_datetime($event['epoch'], $viewer),
        phutil_tag(
          'a',
          array(
            'href' => '/'.$task->getMonogram(),
          ),
          $task->getMonogram().': '.$task->getTitle()),
        $event['title'],
      );
    }

    $table = id(new AphrontTableView($rows))
      ->setHeaders(
        array(
          pht('When'),
          pht('Task'),
          pht('Action'),
        ))
      ->setColumnClasses(
        array(
          '',
          '',
          'wide',
        ));

    $box = id(new PHUIObjectBoxView())
      ->setHeaderText(pht('Events related to this sprint'))
      ->appendChild($table);

    return $box;
  }

  /**
   * Extract important events (the times when tasks were opened or closed)
   * from a list of transactions.
   *
   * @param list<ManiphestTransaction> List of transactions.
   * @param list<phid> List of project PHIDs to emit "scope" events for.
   * @return list<dict> Chronologically sorted events.
   */
  private function extractEvents($xactions, array $scope_phids) {
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
          if ($old === null) {
            // This would show as "reopened" even though it's when the task was
            // created so we skip it. Instead we will use the title for created
            // events
            break;
          }

          if ($new_is_closed) {
            $event_type = 'close';
          } else {
            $event_type = 'reopen';
          }
          break;

        case ManiphestTransaction::TYPE_TITLE:
          if ($old === null)
          {
            $event_type = 'create';
          }
          break;

        case ManiphestTransaction::TYPE_PROJECTS:
          $old = array_fuse($old);
          $new = array_fuse($new);

          $in_old_scope = array_intersect_key($scope_phids, $old);
          $in_new_scope = array_intersect_key($scope_phids, $new);

          if ($in_new_scope && !$in_old_scope) {
            $event_type = 'task-add';
          } else if ($in_old_scope && !$in_new_scope) {
            // NOTE: We will miss some of these events, becuase we are only
            // examining tasks that are currently in the project. If a task
            // is removed from the project and not added again later, it will
            // just vanish from the chart completely, not show up as a
            // scope contraction. We can't do better until the Facts application
            // is avialable without examining *every* task.
            $event_type = 'task-remove';
          }
          break;

        case PhabricatorTransactions::TYPE_CUSTOMFIELD:
          if ($xaction->getMetadataValue('customfield:key') == 'isdc:sprint:storypoints') {
            // POINTS!
            $event_type = 'points';
          }

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
          'key'   => $xaction->getMetadataValue('customfield:key'),
          'type'  => $event_type,
          'title' => $xaction->getTitle(),
        );
      }
    }

    // Sort all events chronologically.
    $events = isort($events, 'epoch');

    return $events;
  }

}
