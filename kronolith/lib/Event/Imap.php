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

class Kronolith_Event_Imap extends Kronolith_Event_Kolab
{
    public function getDriver()
    {
        return Kronolith::getDriver('Imap', $this->calendar);
    }
}
?>