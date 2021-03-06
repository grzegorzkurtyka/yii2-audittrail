<?php
namespace grzegorzkurtyka\yii2audittrail\behaviors;

use Yii;
use yii\base\Behavior;
use yii\db\ActiveRecord;
use grzegorzkurtyka\yii2audittrail\models\AuditTrail;
use grzegorzkurtyka\yii2audittrail\models\AuditTrailChange;
use Exception;

class LoggableBehavior extends Behavior
{

    private $_oldattributes = array();
    public $allowed = array();
    public $ignored = array();
    public $ignoredClasses = array();
    public $dateFormat = 'Y-m-d H:i:s';
    public $userAttribute = null;
    public $storeTimestamp = false;
    public $skipNulls = true;
    public $active = true;

    public function events()
    {
        return [
            ActiveRecord::EVENT_AFTER_FIND => 'afterFind',
            ActiveRecord::EVENT_AFTER_INSERT => 'afterInsert',
            ActiveRecord::EVENT_AFTER_UPDATE => 'afterUpdate',
            ActiveRecord::EVENT_AFTER_DELETE => 'afterDelete',
        ];
    }

    public function afterDelete($event)
    {
        $this->leaveTrail('DELETE');
    }

    public function afterFind($event)
    {
        $this->setOldAttributes($this->owner->getAttributes());
    }

    public function afterInsert($event)
    {
        $this->audit(true);
    }

    public function afterUpdate($event)
    {
        $this->audit(false);
    }

    public function audit($insert)
    {

        $allowedFields = $this->allowed;
        $ignoredFields = $this->ignored;
        $ignoredClasses = $this->ignoredClasses;

        $newattributes = $this->owner->getAttributes();
        $oldattributes = $this->getOldAttributes();

        // Lets check if the whole class should be ignored
        if (sizeof($ignoredClasses) > 0) {
            if (array_search(get_class($this->owner), $ignoredClasses) !== false)
                return;
        }

        // Lets unset fields which are not allowed
        if (sizeof($allowedFields) > 0) {
            foreach ($newattributes as $f => $v) {
                if (array_search($f, $allowedFields) === false)
                    unset($newattributes[$f]);
            }

            foreach ($oldattributes as $f => $v) {
                if (array_search($f, $allowedFields) === false)
                    unset($oldattributes[$f]);
            }
        }

        // Lets unset fields which are ignored
        if (sizeof($ignoredFields) > 0) {
            foreach ($newattributes as $f => $v) {
                if (array_search($f, $ignoredFields) !== false)
                    unset($newattributes[$f]);
            }

            foreach ($oldattributes as $f => $v) {
                if (array_search($f, $ignoredFields) !== false)
                    unset($oldattributes[$f]);
            }
        }

        // If no difference then WHY?
        // There is some kind of problem here that means "0" and 1 do not diff for array_diff so beware: stackoverflow.com/questions/12004231/php-array-diff-weirdness :S
        if (count(array_diff_assoc($newattributes, $oldattributes)) <= 0)
            return;

        // If this is a new record lets add a CREATE notification
        $action = $insert ? 'CREATE' : 'CHANGE';
        $this->auditAttributes($action, $newattributes, $oldattributes);

        // Reset old attributes to handle the case with the same model instance updated multiple times
        $this->setOldAttributes($this->owner->getAttributes());
    }

    public function auditAttributes($action, $newattributes, $oldattributes = array())
    {
        $change = $this->leaveTrail($action);
        foreach ($newattributes as $name => $value) {
            $old = isset($oldattributes[$name]) ? $oldattributes[$name] : '';

            // If we are skipping nulls then lets see if both sides are null
            if ($this->skipNulls && empty($old) && empty($value)) {
                continue;
            }

            // If they are not the same lets write an audit log
            if ($value != $old) {
                $this->leaveTrailChange($change, $name, $value, $old);
            }
        }
    }

    protected function leaveTrail($action)
    {
        $stamp = $this->storeTimestamp ? time() : date($this->dateFormat); // If we are storing a timestamp lets get one else lets get the date
        $log = new AuditTrail([
            'action' => $action,
            'model' => $this->owner->className(),
            'model_id' => (string) $this->getNormalizedPk(),
            'stamp' => $stamp,
            'user_id' => (string) $this->getUserId(), // Lets get the user id
        ]);
        if ($log->save()) {
            return $log;
        }
        return null;
    }

    protected function leaveTrailChange($audit, $field, $value, $old_value) {
        $change = new AuditTrailChange();
        $change->audit_id = $audit->id;
        $change->field = $field;
        $change->new_value = $value;
        $change->old_value = $old_value;
        $change->save();
    }

    public function getOldAttributes()
    {
        return $this->_oldattributes;
    }

    public function setOldAttributes($value)
    {
        $this->_oldattributes = $value;
    }

    public function getUserId()
    {
        if (isset($this->userAttribute)) {
            $data = $this->owner->getAttributes();
            return isset($data[$this->userAttribute]) ? $data[$this->userAttribute] : null;
        } else {
            try {
                $userid = Yii::$app->user->id;
                return empty($userid) ? null : $userid;
            } catch (Exception $e) { //If we have no user object, this must be a command line program
                return null;
            }
        }
    }

    protected function getNormalizedPk()
    {
        $pk = $this->owner->getPrimaryKey();
        return is_array($pk) ? json_encode($pk) : $pk;
    }
}
