<?php
/**
 * The Kronolith_Driver_Imap class implements the Kronolith_Driver API for an
 * Imap Backend
 *
 * Copyright 1999-2011 Horde LLC (http://www.horde.org/)
 *
 * See the enclosed file COPYING for license information (GPL). If you
 * did not receive this file, see http://www.horde.org/licenses/gpl.
 *
 * @author  Guillaume Gentile <ggentile@dorfsvald.net>
 * @package Kronolith
 */
class Kronolith_Driver_Imap extends Kronolith_Driver
{
    /**
     * Our Imap client objects
     *
     * @var Horde_Imap_Client
     */
    protected $_imap = array();

    /**
     * Internal cache of Kronolith_Event_Imap. eventID/UID is key
     *
     * @var array
     */
    private $_events_cache;
    
    /**
    * Internal cache of mails uids. eventID/UID corresponding to the mail is key
    * 
    * @var array
    */
    private $_uids_cache;

    /**
     * The class name of the event object to instantiate.
     *
     * Can be overwritten by sub-classes.
     *
     * @var string
     */
    protected $_eventClass = 'Kronolith_Event_Imap';

    /**
     * Indicates if we have synchronized this folder
     *
     * @var boolean
     */
    private $_synchronized;

    /**
     * The handler to parse xml to Kolab Events
     *
     * @var Horde_Kolab_Format
     */
    private $_kolabFormat;

    /**
     * Reset internal variable on share change
     */
    public function reset()
    {
        $this->_events_cache = array();
        $this->_uids_cache = array();
        $this->_synchronized = false;
    }

    /**
     * Attempts to open an Imap Kolab-Xml folder.
     */
    public function initialize()
    {
        $imap_config = array(
              'hostspec' => empty($this->_params['hostspec']) ? null : $this->_params['hostspec'],
              'password' => empty($this->_params['pass']) ? null : $this->_params['pass'],
              'port' => empty($this->_params['port']) ? null : $this->_params['port'],
              'secure' => ($this->_params['secure'] == 'none') ? null : $this->_params['secure'],
              'username' => $this->_params['username']
        );

        $this->_imap = Horde_Imap_Client::factory('Socket', $imap_config);
        $this->reset();

        if (!isset($this->_kolabFormat)) {
            $factory = new Horde_Kolab_Format_Factory();
            $this->_kolabFormat = $factory->create('XML', 'event');
        }

        $this->calendar = $this->_params['folder'];
    }

    /**
     *
     * Returns the imap connection and creates it if doesn't exist
     *
     * @return Horde_Imap_Client_Socket
     */
    protected function  getImap()
    {
        if (! isset($this->_imap))
        {
            $imap_config = array(
              'hostspec' => empty($this->_params['hostspec']) ? null : $this->_params['hostspec'],
              'password' => empty($this->_params['pass']) ? null : $this->_params['pass'],
              'port' => empty($this->_params['port']) ? null : $this->_params['port'],
              'secure' => ($this->_params['secure'] == 'none') ? null : $this->_params['secure'],
              'username' => $user
            );

            $this->_imap = Horde_Imap_Client::factory('Socket', $imap_config);
        }

        return $this->_imap;
    }

    /**
     * Retrieves All uids
     *
     * @return array The message ids.
     */
    protected function _getUids()
    {
        $search_query = new Horde_Imap_Client_Search_Query();
        $search_query->flag('DELETED', false);
        //$search_query->uid ();
        $uidsearch = $this->getImap()->search($this->_params['folder'], $search_query);
        $uids = $uidsearch['match'];
        return $uids;
    }

    /**
     * Retrieves a bodypart for the given message ID and mime part ID.
     *
     * @param string $folder The folder to fetch the messages from.
     * @param array  $uid                 The message UID.
     * @param array  $id                  The mime part ID.
     *
     * @return resource|string The body part, as a stream resource or string.
     */
    protected function _fetchBodypart($uid, $id)
    {
        $query = new Horde_Imap_Client_Fetch_Query();
        $query->bodyPart($id);

        $ret = $this->getImap()->fetch(
            $this->_params['folder'],
            $query,
            array('ids' => new Horde_Imap_Client_Ids($uid))
        );

        return quoted_printable_decode($ret[$uid]->getBodyPart($id));
    }

    // We delay initial synchronization to the first use
    // so multiple calendars don't add to the total latency.
    // This function must be called before all internal driver functions
    public function synchronize($force = false)
    {
        if ($this->_synchronized && !$force) {
            return;
        }

        $this->_events_cache = array();
        $this->_uids_cache = array();
        foreach ($this->_getUids() as $uid)
        {
            $xml = $this->_fetchBodypart($uid, 2);
            if (strlen($xml) > 0) {
                $event = $this->_kolabFormat->load($xml);
                $this->_events_cache[$event['uid']] = new Kronolith_Event_Imap($this, $event);
                $this->_uids_cache[$event['uid']] = $uid;
            }
        }

        $this->_synchronized = true;
    }

    /**
     * Lists all events in the time range, optionally restricting results to
     * only events with alarms.
     *
     * @param Horde_Date $startDate      Start of range date object.
     * @param Horde_Date $endDate        End of range data object.
     * @param boolean $showRecurrence    Return every instance of a recurring
     *                                   event? If false, will only return
     *                                   recurring events once inside the
     *                                   $startDate - $endDate range.
     * @param boolean $hasAlarm          Only return events with alarms?
     * @param boolean $json              Store the results of the events'
     *                                   toJson() method?
     * @param boolean $coverDates        Whether to add the events to all days
     *                                   that they cover.
     * @param boolean $hideExceptions    Hide events that represent exceptions
     *                                   to a recurring event (baseid is set)?
     * @param boolean $fetchTags         Whether to fetch tags for all events
     *
     * @return array  Events in the given time range.
     * @throws Kronolith_Exception
     */
    public function listEvents(Horde_Date $startDate = null,
    Horde_Date $endDate = null,
    $showRecurrence = false, $hasAlarm = false,
    $json = false, $coverDates = true,
    $hideExceptions = false, $fetchTags = false)
    {
        $this->synchronize();

        if (empty($startDate)) {
            $startDate = new Horde_Date(array('mday' => 1,
                                              'month' => 1,
                                              'year' => 0000));
        }
        if (empty($endDate)) {
            $endDate = new Horde_Date(array('mday' => 31,
                                            'month' => 12,
                                            'year' => 9999));
        }
        if (!($startDate instanceOf Horde_Date)) {
            $startDate = new Horde_Date($startDate);
        }
        if (!($endDate instanceOf Horde_Date)) {
            $endDate = new Horde_Date($endDate);
        }

        $startDate = clone $startDate;
        $startDate->hour = $startDate->min = $startDate->sec = 0;
        $endDate = clone $endDate;
        $endDate->hour = 23;
        $endDate->min = $endDate->sec = 59;

        $events = array();
        foreach($this->_events_cache as $event) {
            if ($hasAlarm && !$event->alarm) {
                continue;
            }

            /* Ignore events out of the period. */
            if (
            /* Starts after the period. */
            $event->start->compareDateTime($endDate) > 0 ||
            /* End before the period and doesn't recur. */
            (!$event->recurs() &&
            $event->end->compareDateTime($startDate) < 0) ||
            /* Recurs and ... */
            ($event->recurs() &&
            /* ... has a recurrence end before the period. */
            ($event->recurrence->hasRecurEnd() &&
            $event->recurrence->recurEnd->compareDateTime($startDate) < 0))) {
                continue;
            }

            Kronolith::addEvents($events, $event, $startDate, $endDate,
            $showRecurrence, $json, $coverDates);
        }

        return $events;
    }

    /**
     * List all alarms occuring after a given date
     *
     * @param Horde_Date $date Begin date
     *
     * @param bool $fullevent
     *
     * @return array
     *
     * @throws Kronolith_Exception
     */
    public function listAlarms($date, $fullevent = false)
    {
        $allevents = $this->listEvents($date, null, false, true);
        $events = array();

        foreach ($allevents as $eventId => $data) {
            $event = $this->getEvent($eventId);
            if (!$event->recurs()) {
                $start = new Horde_Date($event->start);
                $start->min -= $event->alarm;
                if ($start->compareDateTime($date) <= 0 &&
                $date->compareDateTime($event->end) <= -1) {
                    $events[] = $fullevent ? $event : $eventId;
                }
            } else {
                if ($next = $event->recurrence->nextRecurrence($date)) {
                    if ($event->recurrence->hasException($next->year, $next->month, $next->mday)) {
                        continue;
                    }
                    $start = new Horde_Date($next);
                    $start->min -= $event->alarm;
                    $end = new Horde_Date(array('year' => $next->year,
                                                'month' => $next->month,
                                                'mday' => $next->mday,
                                                'hour' => $event->end->hour,
                                                'min' => $event->end->min,
                                                'sec' => $event->end->sec));
                    if ($start->compareDateTime($date) <= 0 &&
                    $date->compareDateTime($end) <= -1) {
                        if ($fullevent) {
                            $event->start = $start;
                            $event->end = $end;
                            $events[] = $event;
                        } else {
                            $events[] = $eventId;
                        }
                    }
                }
            }
        }

        return is_array($events) ? $events : array();
    }

    /**
     * Checks if the event's UID already exists and returns all event
     * ids with that UID.
     *
     * @param string $uid          The event's uid.
     * @param string $calendar_id  Calendar to search in.
     *
     * @return string|boolean  Returns a string with event_id or false if
     *                         not found.
     * @throws Kronolith_Exception
     */
    public function exists($uid, $calendar_id = null)
    {
        /*
         // Log error if someone uses this function in an unsupported way
         if ($calendar_id != $this->calendar) {
         Horde::logMessage(sprintf("Kolab::exists called for calendar %s. Currently active is %s.", $calendar_id, $this->calendar), 'ERR');
         throw new Kronolith_Exception(sprintf("Kolab::exists called for calendar %s. Currently active is %s.", $calendar_id, $this->calendar));
         }

         $result = $this->synchronize();

         if ($this->_data->objectIdExists($uid)) {
         return $uid;
         }

         return false;*/
        $result = $this->synchronize();

        return array_key_exists($uis,$this->_events_cache)? $uid : false ;
    }

    /**
     *
     * Returns the event identified by $eventId or a new Event if none precised
     *
     * @param string $eventId
     *
     * @return Kronolith_Event_Imap
     *
     * @throws Kronolith_Exception
     * @throws Horde_Exception_NotFound
     */
    public function getEvent($eventId = null)
    {
        if (!strlen($eventId)) {
            return new Kronolith_Event_Imap($this);
        }

        $result = $this->synchronize();

        if (array_key_exists($eventId, $this->_events_cache)) {
            return $this->_events_cache[$eventId];
        }

        throw new Horde_Exception_NotFound(sprintf(_("Event not found: %s"), $eventId));
    }

    /**
     * Get an event or events with the given UID value.
     *
     * @param string $uid The UID to match
     * @param array $calendars A restricted array of calendar ids to search
     * @param boolean $getAll Return all matching events? If this is false,
     * an error will be returned if more than one event is found.
     *
     * @return Kronolith_Event
     * @throws Kronolith_Exception
     * @throws Horde_Exception_NotFound
     */
    public function getByUID($uid, $calendars = null, $getAll = false)
    {
       
        $this->synchronize();

        if (!array_key_exists($uid, $this->_events_cache)) {
            continue;
        }

        // Ok, found event
        $event = $this->_events_cache[$uid];

        if ($getAll) {
            $events = array();
            $events[] = $event;
            return $events;
        } else {
            return $event;
        }
        throw new Horde_Exception_NotFound(sprintf(_("Event not found: %s"), $uid));
    }

    /**
     * Updates an existing event in the backend.
     *
     * @param Kronolith_Event $event  The event to save.
     *
     * @return string  The event id.
     * @throws Horde_Mime_Exception
     */
    protected function _updateEvent(Kronolith_Event $event)
    {
        return $this->_saveEvent($event, true);
    }

    /**
     * Adds an event to the backend.
     *
     * @param Kronolith_Event $event  The event to save.
     *
     * @return string  The event id.
     * @throws Horde_Mime_Exception
     */
    protected function _addEvent(Kronolith_Event $event)
    {
        return $this->_saveEvent($event, false);
    }

    /**
     * Saves an event in the backend.
     *
     * @param Kronolith_Event $event  The event to save.
     *
     * @return string  The event id.
     * @throws Horde_Mime_Exception
     */
    protected function _saveEvent($event, $edit)
    {
        $this->synchronize();

        $action = $edit
            ? array('action' => 'modify')
            : array('action' => 'add');

        if (!$event->uid) {
            $event->uid = strval(new Horde_Support_Uuid());
        }

        if (!$edit) {
            $this->getImap()->append($this->_params['folder'], $this->generateMail($event));
        } 
        else {
            $this->getImap()->store($this->_params['folder'], array(
                'add' => array('\\deleted'),
                'ids' => new Horde_Imap_Client_Ids($this->_uids_cache[$event->uid])
            ));
            $this->getImap()->expunge($this->_params['folder']);

            $this->getImap()->append($this->_params['folder'], $this->generateMail($event,time()));
        }

        /* Deal with tags */
        if ($edit) {
            Kronolith::getTagger()->replaceTags($event->uid, $event->tags, $event->creator, 'event');
        } else {
            Kronolith::getTagger()->tag($event->uid, $event->tags, $event->creator, 'event');
        }

        $cal = $GLOBALS['kronolith_shares']->getShare($event->calendar);

        /* Notify about the changed event. */
        Kronolith::sendNotification($event, $edit ? 'edit' : 'add');

        /* Log the creation/modification of this item in the history log. */
        try {
            $GLOBALS['injector']->getInstance('Horde_History')->log('kronolith:' . $event->calendar . ':' . $event->uid, $action, true);
        } catch (Exception $e) {
            Horde::logMessage($e, 'ERR');
        }

        // refresh IMAP cache
        $this->synchronize(true);

        if (is_callable('Kolab', 'triggerFreeBusyUpdate')) {
            //Kolab::triggerFreeBusyUpdate($this->_data->parseFolder($event->calendar));
        }

        return $event->uid;
    }
    /**
     * Generates a mail to store in the backend
     *
     * @param Kronolith_Event_Imap $event  The event to save.
     *
     * @throws Horde_Mime_Exception
     */
    protected function generateMail($event, $creationDate = null)
    {
        if (is_null($creationDate)) {
            $creationDate = time();
        }
        $boundary = "Boundary-00=_".md5(uniqid(rand()));

        $header ='From: '.$this->_params['username'].' <'.$this->_params['username'].'@'.$this->_params['hostspec'].">\r\n";
        $header.="Subject: $event->uid\r\n";
        $header.='Date: '.strftime('%a, %d %b %Y %H:%M:%S %z',$creationDate)."\r\n";
        $header.="User-Agent: Kronolith Imap Driver 0.1\r\n";
        $header.="MIME-Version: 1.0\r\n";
        $header.="X-Kolab-Type: application/x-vnd.kolab.event\r\n";
        $header.="Content-Type: Multipart/Mixed;\r\n";
        $header.="  boundary=\"$boundary\"\r\n";
        $header.="Status: RO\r\n\r\n";
        $header.="--$boundary\r\n";

        $message ="Content-Type: Text/Plain;\r\n";
        $message.="  charset=\"us-ascii\"\r\n";
        $message.="Content-Transfer-Encoding: 7bit\r\n";
        $message.="Content-Disposition:\r\n";
        $message.="\r\n";
        $message.="This is a Kolab Groupware object.\r\n";
        $message.="To view this object you will need an email client that can understand the Kolab Groupware format.\r\n";
        $message.="For a list of such email clients please visit\r\n";
        $message.="http://www.kolab.org/kolab2-clients.html\r\n";
        $message.="--$boundary\r\n";

        $xml_attachment ="Content-Type: application/x-vnd.kolab.event;\r\n";
        $xml_attachment.="  name=\"kolab.xml\"\r\n";
        $xml_attachment.="Content-Transfer-Encoding: 7bit\r\n";
        $xml_attachment.="Content-Disposition: attachment;\r\n";  
        $xml_attachment.="  filename=\"kolab.xml\"\r\n\r\n";
        $xml_attachment.= $this->_kolabFormat->save($event->toKolab())." \r\n";
        $xml_attachment.="--$boundary--\r\n";

        $mail = array();
        $mail[] = array('data'  => $header.$message.$xml_attachment,
                        'flags' => array('\Seen')
        );
        return $mail ;
    }

    /**
     * Stub to be overridden in the child class.
     *
     * @throws Kronolith_Exception
     */
    protected function _move($eventId, $newCalendar)
    {
        return;
    }
    


    /**
     * Delete a calendar and all its events.
     *
     * @param string $calendar  The name of the calendar to delete.
     *
     * @throws Kronolith_Exception
     */
    public function delete($calendar)
    {
        /*
        $this->open($calendar);
        $result = $this->synchronize();

        foreach($this->listEvents() as $event) {
            $this->deleteEvent($event->uid);
        }
        */
        return;
    }

    /**
     * Delete an event.
     *
     * @param string $eventId  The ID of the event to delete.
     *
     * @throws Kronolith_Exception
     * @throws Horde_Exception_NotFound
     * @throws Horde_Mime_Exception
     */
    public function deleteEvent($eventId, $silent = false)
    {
        
        $result = $this->synchronize();

        if (!$this->exists($eventId)) {
            throw new Kronolith_Exception(sprintf(_("Event not found: %s"), $eventId));
        }

        $event = $this->getEvent($eventId);
        
        $this->getImap()->store($this->_params['folder'], array(
            'add' => array('\\deleted'),
            'ids' => new Horde_Imap_Client_Ids($this->_uids_cache[$eventId])
        ));

        // Notify about the deleted event.
        if (!$silent) {
            Kronolith::sendNotification($event, 'delete');
        }

        // Log the deletion of this item in the history log.
        try {
            $GLOBALS['injector']->getInstance('Horde_History')->log('kronolith:' . $event->calendar . ':' . $event->uid, array('action' => 'delete'), true);
        } catch (Exception $e) {
            Horde::logMessage($e, 'ERR');
        }

        if (is_callable('Kolab', 'triggerFreeBusyUpdate')) {
            //Kolab::triggerFreeBusyUpdate($this->_data->parseFolder($event->calendar));
        }

        unset($this->_events_cache[$eventId]);
        unset($this->_uids_cache[$eventId]);
        $this->getImap()->expunge($this->_params['folder']);
        
        return;
    }
    
    
    
    
    
    
}
