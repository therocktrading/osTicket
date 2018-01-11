<?php
/*********************************************************************
    http.php

    HTTP controller for the osTicket API

    Jared Hancock
    Copyright (c)  2006-2013 osTicket
    http://www.osticket.com

    Released under the GNU General Public License WITHOUT ANY WARRANTY.
    See LICENSE.TXT for details.

    vim: expandtab sw=4 ts=4 sts=4:
**********************************************************************/
// Use sessions — it's important for SSO authentication, which uses
// /api/auth/ext
define('DISABLE_SESSION', false);

require 'api.inc.php';

# Include the main api urls
require_once INCLUDE_DIR."class.dispatcher.php";

$dispatcher = patterns('',
        url_post("^/tickets\.(?P<format>xml|json|email)$", array('api.tickets.php:TicketApiController','create')),
        url('^/tasks/', patterns('',
                url_post("^cron$", array('api.cron.php:CronApiController', 'execute'))
         )),
        url_get("^/tickets\.(?P<format>json)$", array('api.tickets.php:TicketApiController','get')),
        url_get("^/tickets/(?P<ticket_number>\d*)\.(?P<format>json)$", array('api.tickets.php:TicketApiController','getSingle')),
        url_get("^/tickets/(?P<ticket_number>\d*)/attachments/(?P<attachment_id>\d*)\.(?P<format>json)$", array('api.tickets.php:TicketApiController','getAttachment')),
        url_post("^/tickets/(?P<ticket_number>\d*)\.(?P<format>json)$", array('api.tickets.php:TicketApiController','replyToTicket'))
        );

Signal::send('api', $dispatcher);

# Call the respective function
print $dispatcher->resolve($ost->get_path_info());
?>
