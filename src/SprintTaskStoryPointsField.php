<?php

final class SprintTaskStoryPointsField extends ManiphestCustomField
  implements PhabricatorStandardCustomFieldInterface {

  public function __construct() {
    $proxy = id(new PhabricatorStandardCustomFieldText())
      ->setFieldKey($this->getFieldKey())
      ->setApplicationField($this)
      ->setFieldConfig(array(
        'name' => $this->getFieldName(),
        'description' => $this->getFieldDescription(),
      ));

    $this->setProxy($proxy);
  }

  public function canSetProxy() {
    return true;
  }

  // == General field identity stuff
  public function getFieldKey() {
    return 'isdc:sprint:storypoints';
  }

  public function getFieldName() {
    return 'Story Points';
  }

  public function getFieldDescription() {
    return 'Estimated story points for this task';
  }

  public function getStandardCustomFieldNamespace() {
    return 'maniphest';
  }

  public function showField() {
    static $show = null;

    if ($show == null)
    {
      if (empty($this->getObject()->getProjectPHIDs())) {
        return $show = false;
      }
      // Fetch the names from all the Projects associated with this task
      $projects = id(new PhabricatorProject())
        ->loadAllWhere(
        'phid IN (%Ls)',
        $this->getObject()->getProjectPHIDs());
      $names = mpull($projects, 'getName');

      // Set show to true if one of the Projects contains "Sprint"
      $show = false;
      foreach($names as $name) {
        if (strpos($name, 'Sprint') !== false) {
          $show = true;
        }
      }
    }

    return $show;
  }

  public function renderPropertyViewLabel() {
    if (!$this->showField()) {
      return;
    }

    if ($this->getProxy()) {
      return $this->getProxy()->renderPropertyViewLabel();
    }
    return $this->getFieldName();
  }

  public function renderPropertyViewValue(array $handles) {
    if (!$this->showField()) {
      return;
    }

    if ($this->getProxy()) {
      return $this->getProxy()->renderPropertyViewValue($handles);
    }
    throw new PhabricatorCustomFieldImplementationIncompleteException($this);
  }

  public function shouldAppearInEditView() {
    return true;
  }

  public function renderEditControl(array $handles) {
    if (!$this->showField()) {
      return;
    }

    if ($this->getProxy()) {
      return $this->getProxy()->renderEditControl($handles);
    }
    throw new PhabricatorCustomFieldImplementationIncompleteException($this);
  }

  // == Search
  public function shouldAppearInApplicationSearch()
  {
    return true;
  }

}
