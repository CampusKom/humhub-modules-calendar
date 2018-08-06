<?php
namespace humhub\modules\calendar\models;

use humhub\modules\calendar\interfaces\AbstractCalendarQuery;
use humhub\modules\cfiles\models\rows\AbstractFileSystemItemRow;
use humhub\modules\content\components\ContentContainerActiveRecord;
use yii\helpers\ArrayHelper;
use Yii;
use humhub\modules\space\models\Space;
use DateTime;
use DateInterval;
use humhub\modules\user\models\User;
use humhub\modules\content\components\ActiveQueryContent;

/**
 * CalendarEntryQuery class can be used for creating filter queries for [[CalendarEntry]] models.
 * 
 * The class follows the builder pattern and can be used as follows:
 * 
 *  ```php
 * // Find all CalendarEntries of user profile of $user1 
 * CalendarEntryQuery::find()->container($user1)->limit(20)->all();
 * 
 * // Find all entries from 3 days in the past till three days in the future
 * CalendarEntryQuery::find()->from(-3)->to(3)->all();
 * 
 * // Find all entries within today at 00:00 till three days in the future at 23:59
 * CalendarEntryQuery::find()->days(3)->all();
 * 
 * // Filter entries where the current user is participating
 * CalendarEntryQuery::find()->participate();
 * ```
 * 
 * > Note: If [[from()]] and [[to()]] is set, the query will use an open range query by default, which
 * means either the start time or the end time of the [[CalendarEntry]] has to be within the searched interval.
 * This behaviour can be changed by using the [[openRange()]]-method. If the openRange behaviour is deactivated
 * only entries with start and end time within the search interval will be included.
 * 
 * > Note: By default we are searching in whole day intervals and get rid of the time information of from/to boundaries by setting
 * the time of the from date to 00:00:00 and the time of the end date to 23:59:59. This behaviour can be deactivated by using the [[withTime()]]-method.
 * 
 * The following filters are available:
 * 
 *  - [[from()]]: Date filter interval start
 *  - [[to()]]: Date filter interval end
 *  - [[days()]]: Filter by future or past day interval
 *  - [[months()]]: Filter by future or past month interval
 *  - [[years()]]: Filter by future or past year interval
 * 
 *  - [[container()]]: Filter by container
 *  - [[userRelated()]]: Adds a user relation by the given or default scope (e.g: Following Spaces, Member Spaces, Own Profile, etc.)
 *  - [[participant()]]: Given user accepted invitation
 *  - [[mine()]]: Entries created by the given user
 *  - [[responded()]]: Entries where given user has given any response (accepted/declined...)
 *  - [[notResponded()]]: Entries where given user has not given any response yet (accepted/declined...)
 *
 * @author buddha
 */
class CalendarEntryQuery extends AbstractCalendarQuery
{
    /**
     * @inheritdocs
     */
    protected static $recordClass = CalendarEntry::class;

    /**
     * @var bool true if the participant join has already been added else false
     */
    private $praticipantJoined = false;

    public $endRecurField = 'recur_end';
    protected $_recur = null;

    protected $_recurEnd = null;

    public function filterResponded()
    {
        $this->participantJoin();
        $this->_query->andWhere(['IS NOT', 'calendar_entry_participant.id', new \yii\db\Expression('NULL')]);
    }

    public function filterNotResponded()
    {
        $this->participantJoin();
        $this->_query->andWhere(['IS', 'calendar_entry_participant.id', new \yii\db\Expression('NULL')]);
    }

    public function filterIsParticipant()
    {
        $this->participantJoin();
        $this->_query->andWhere(['calendar_entry_participant.participation_state' => CalendarEntryParticipant::PARTICIPATION_STATE_ACCEPTED]);
    }

    private function participantJoin()
    {
        if(!$this->praticipantJoined) {
            $this->_query->leftJoin('calendar_entry_participant', 'calendar_entry.id=calendar_entry_participant.calendar_entry_id AND calendar_entry_participant.user_id=:userId', [':userId' => $this->_user->id]);
            $this->praticipantJoined = true;
        }
    }

    
    /**
     * @param DateTime $start
     * @param DateTime $end
     * @param ContentContainerActiveRecord $container
     * @param array $filters
     * @param int $limit
     * @return array|\yii\db\ActiveRecord[]
     */
    public static function findForFilter(DateTime $start, DateTime $end, ContentContainerActiveRecord $container = null, $filters = [], $limit = 50)
    {
        $non_recurrent = Parent::findForFilter($start, 
                                    $end,$container, 
                                    $filters, $limit);

        //recurring event
        $recurrent = static::getRecurringEntriesbyRange($start,$end,$start,$container,$filters,$limit);
        $result = ArrayHelper::merge($non_recurrent,$recurrent);

        return $result;
    }

       //Return array of recurring event between specific start and end time input
       public static function getRecurringEntriesbyRange(DateTime $start, DateTime $end,DateTime $recur_end, ContentContainerActiveRecord $container = null, $filters = [], $limit = 50)
       {
        
         $recurrent_model =  static::find()
                                   ->container($container)
                                   ->recur(true,$start)
                                   ->filter($filters)
                                   ->limit($limit)->all();

            // echo var_dump($this->query()->createCommand()->sql);
         $recurrent = array();
         foreach($recurrent_model as $model_item)//each reccurent event 
         {       //  generate all the dates it has to show up in  
            $dateStart = new DateTime($model_item->start_datetime);
            $dateEnd = new DateTime($model_item->end_datetime);
            $recurEndTime = new DateTime($model_item->recur_end);

            $dateStart->modify('+'.$model_item->recur_interval.' '.CalendarEntry::getRecurType($model_item->recur_type));
            $dateEnd->modify('+'.$model_item->recur_interval.' '.CalendarEntry::getRecurType($model_item->recur_type));

         while($dateStart <= $end && $dateStart <= $recurEndTime)
           {     //   clone original event base on recurr logic
             $recurring_item = new CalendarEntry; //clone original event
             $recurring_item->attributes = $model_item->attributes;
             $recurring_item->id = $model_item->id;
             $recurring_item->start_datetime = $dateStart->format('Y-m-d H:i:s'); //set new start date
             $recurring_item->end_datetime = $dateEnd->format('Y-m-d H:i:s'); //set new end date
             array_push($recurrent,$recurring_item);

            $dateStart->modify('+'.$model_item->recur_interval.' '.CalendarEntry::getRecurType($model_item->recur_type));
            $dateEnd->modify('+'.$model_item->recur_interval.' '.CalendarEntry::getRecurType($model_item->recur_type));
           }
         }
         return $recurrent;
       }
     
   
       /**
        * Sets up the actual filter query.
        */


            /**
     * Sets up the date interval filter with respect to the openRange setting.
     */
    protected function recur($recur,$recurEnd)
    {
        $this->_recur = $recur ;
        $this->_recurEnd = $recurEnd;
        return $this;
    }

    protected function getRecurCriteria(DateTime $date, $eq = '>=')
    {
        return [$eq, $this->endRecurField, $date->format($this->dateFormat)];
    }

    protected function setupDateCriteria()
    {
        if ($this->_openRange && $this->_from && $this->_to) {
            //Search for all dates with start and/or end within the given range
            $this->_query->andFilterWhere(
                ['or',
                    ['and',
                        $this->getStartCriteria($this->_from, '>='),
                        $this->getStartCriteria($this->_to, '<=')
                    ],
                    ['and',
                        $this->getEndCriteria($this->_from, '>='),
                        $this->getEndCriteria($this->_to, '<=')
                    ]
                ]
            );
            return;
        }

        if ($this->_from) {
            $this->_query->andWhere($this->getStartCriteria($this->_from));
        }

        if ($this->_to) {
            $this->_query->andWhere($this->getEndCriteria($this->_to));
        }

        if ($this->_recur) {
            $this->_query->andWhere(['=','recur',1])
                         ->andWhere($this->getRecurCriteria($this->_recurEnd));
         }

    }

}
