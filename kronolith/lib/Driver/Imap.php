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
class Kronolith_Driver_Imap extends Kronolith_Driver_Sql
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
        foreach ($this->_getUids() as $uid)
        {
            $xml = $this->_fetchBodypart($uid, 2);
            if (strlen($xml) > 0) {
		            $event = $this->_kolabFormat->load($xml);
                $this->_events_cache[$event['uid']] = new Kronolith_Event_Kolab($this, $event);
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
     * @return Kronolith_Event_Kolab
     * 
     * @throws Kronolith_Exception
     * @throws Horde_Exception_NotFound
     */
    public function getEvent($eventId = null)
    {
        if (!strlen($eventId)) {
            return new Kronolith_Event_Kolab($this);
        }

        $result = $this->synchronize();

        if (array_key_exists($eventId, $this->_events_cache)) {
            return $this->_events_cache[$eventId];
        }

        throw new Horde_Exception_NotFound(sprintf(_("Event not found: %s"), $eventId));
    }
}
