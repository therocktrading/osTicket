<?php

include_once INCLUDE_DIR.'class.api.php';
include_once INCLUDE_DIR.'class.ticket.php';

class TicketApiController extends ApiController {

    # Supported arguments -- anything else is an error. These items will be
    # inspected _after_ the fixup() method of the ApiXxxDataParser classes
    # so that all supported input formats should be supported
    function getRequestStructure($format, $data=null) {
        $supported = array(
            "alert", "autorespond", "source", "topicId",
            "attachments" => array("*" =>
                array("name", "type", "data", "encoding", "size")
            ),
            "message", "ip", "priorityId"
        );
        # Fetch dynamic form field names for the given help topic and add
        # the names to the supported request structure
        if (isset($data['topicId'])
                && ($topic = Topic::lookup($data['topicId']))
                && ($forms = $topic->getForms())) {
            foreach ($forms as $form)
                foreach ($form->getDynamicFields() as $field)
                    $supported[] = $field->get('name');
        }

        # Ticket form fields
        # TODO: Support userId for existing user
        if(($form = TicketForm::getInstance()))
            foreach ($form->getFields() as $field)
                $supported[] = $field->get('name');

        # User form fields
        if(($form = UserForm::getInstance()))
            foreach ($form->getFields() as $field)
                $supported[] = $field->get('name');

        if(!strcasecmp($format, 'email')) {
            $supported = array_merge($supported, array('header', 'mid',
                'emailId', 'to-email-id', 'ticketId', 'reply-to', 'reply-to-name',
                'in-reply-to', 'references', 'thread-type',
                'mailflags' => array('bounce', 'auto-reply', 'spam', 'viral'),
                'recipients' => array('*' => array('name', 'email', 'source'))
                ));

            $supported['attachments']['*'][] = 'cid';
        }

        return $supported;
    }

    /*
     Validate data - overwrites parent's validator for additional validations.
    */
    function validate(&$data, $format, $strict=true) {
        global $ost;

        //Call parent to Validate the structure
        if(!parent::validate($data, $format, $strict) && $strict)
            $this->exerr(400, __('Unexpected or invalid data received'));

        // Use the settings on the thread entry on the ticket details
        // form to validate the attachments in the email
        $tform = TicketForm::objects()->one()->getForm();
        $messageField = $tform->getField('message');
        $fileField = $messageField->getWidget()->getAttachments();

        // Nuke attachments IF API files are not allowed.
        if (!$messageField->isAttachmentsEnabled())
            $data['attachments'] = array();

        //Validate attachments: Do error checking... soft fail - set the error and pass on the request.
        if ($data['attachments'] && is_array($data['attachments'])) {
            foreach($data['attachments'] as &$file) {
                if ($file['encoding'] && !strcasecmp($file['encoding'], 'base64')) {
                    if(!($file['data'] = base64_decode($file['data'], true)))
                        $file['error'] = sprintf(__('%s: Poorly encoded base64 data'),
                            Format::htmlchars($file['name']));
                }
                // Validate and save immediately
                try {
                    $F = $fileField->uploadAttachment($file);
                    $file['id'] = $F->getId();
                }
                catch (FileUploadError $ex) {
                    $file['error'] = $file['name'] . ': ' . $ex->getMessage();
                }
            }
            unset($file);
        }

        return true;
    }


    function create($format) {

        if(!($key=$this->requireApiKey()) || !$key->canCreateTickets())
            return $this->exerr(401, __('API key not authorized'));

        $ticket = null;
        if(!strcasecmp($format, 'email')) {
            # Handle remote piped emails - could be a reply...etc.
            $ticket = $this->processEmail();
        } else {
            # Parse request body
            $ticket = $this->createTicket($this->getRequest($format));
        }

        if(!$ticket)
            return $this->exerr(500, __("Unable to create new ticket: unknown error"));

        $this->response(201, $ticket->getNumber());
    }

    /* private helper functions */

    function createTicket($data) {

        # Pull off some meta-data
        $alert       = (bool) (isset($data['alert'])       ? $data['alert']       : true);
        $autorespond = (bool) (isset($data['autorespond']) ? $data['autorespond'] : true);

        # Assign default value to source if not defined, or defined as NULL
        $data['source'] = isset($data['source']) ? $data['source'] : 'API';

        # Create the ticket with the data (attempt to anyway)
        $errors = array();

        $ticket = Ticket::create($data, $errors, $data['source'], $autorespond, $alert);
        # Return errors (?)
        if (count($errors)) {
            if(isset($errors['errno']) && $errors['errno'] == 403)
                return $this->exerr(403, __('Ticket denied'));
            else
                return $this->exerr(
                        400,
                        __("Unable to create new ticket: validation errors").":\n"
                        .Format::array_implode(": ", "\n", $errors)
                        );
        } elseif (!$ticket) {
            return $this->exerr(500, __("Unable to create new ticket: unknown error"));
        }

        return $ticket;
    }

    function processEmail($data=false) {

        if (!$data)
            $data = $this->getEmailRequest();

        $seen = false;
        if (($entry = ThreadEntry::lookupByEmailHeaders($data, $seen))
            && ($message = $entry->postEmail($data))
        ) {
            if ($message instanceof ThreadEntry) {
                return $message->getThread()->getObject();
            }
            else if ($seen) {
                // Email has been processed previously
                return $entry->getThread()->getObject();
            }
        }

        // Allow continuation of thread without initial message or note
        elseif (($thread = Thread::lookupByEmailHeaders($data))
            && ($message = $thread->postEmail($data))
        ) {
            return $thread->getObject();
        }

        // All emails which do not appear to be part of an existing thread
        // will always create new "Tickets". All other objects will need to
        // be created via the web interface or the API
        return $this->createTicket($data);
    }

    function get($format) {
        if(!($key=$this->requireApiKey())) {
            return $this->exerr(401, __('API key not authorized'));
        }

        $request = $this->getRequest($format);

        if (!array_key_exists('email', $request)) {
            $this->response(400, json_encode(array('error' => 'missing email parameter')));
            return;
        }

        $query = Ticket::objects();
        $tickets = [];
        if (array_key_exists('email', $request)) {
            $query
                ->filter(array('user__emails__address' => $request['email']));
        }

        $tickets = $query->values(
            'ticket_id', 'number', 'created', 'isanswered', 'source', 'status_id',
            'status__state', 'status__name', 'cdata__subject', 'dept_id',
            'dept__name', 'dept__ispublic', 'user__default_email__address', 'lastupdate'
        )
        ->order_by('-created')
        ->all();

        $this->response(200, json_encode($tickets));
    }

    function getSingle($ticket_number, $format) {
        if(!($key=$this->requireApiKey())) {
            return $this->exerr(401, __('API key not authorized'));
        }

        $request = $this->getRequest($format);

        if (!array_key_exists('email', $request)) {
            $this->response(400, json_encode(array('error' => 'missing email parameter')));
            return;
        }

        # Checks for existing ticket with that number
        $id = Ticket::getIdByNumber($ticket_number, $request['email']);
        if ($id <= 0) {
            return $this->response(404, __("Ticket not found"));
        }

        # Load ticket and send response
        $ticket = Ticket::lookup($id);
        $response = array(
            'number' => $ticket->getNumber(),
            'lastupdate' => $ticket->getEffectiveDate(),
            'cdata__subject' => $ticket->getSubject(),
            'status__state' => $ticket->getStatus()->getState(),
            //'priority' => $ticket->getPriority(),
            //'department' => $ticket->getDeptName(),
            //'created_at' => $ticket->getCreateDate(),
            //'user_name' => $ticket->getName()->getFull(),
            //'user_email' => $ticket->getEmail(),
            //'user_phone' => $ticket->getPhoneNumber(),
            //'source' => $ticket->getSource(),
            //'ip' => $ticket->getIP(),
            //'sla' => $ticket->getSLA()->getName(),
            //'due_timestamp' => $ticket->getEstDueDate(),
            //'close_timestamp' => $ticket->getCloseDate(),
            //'help_topic' => $ticket->getHelpTopic(),
            //'last_message_timestamp' => $ticket->getLastMsgDate(),
            //'last_response_timestamp' => $ticket->getLastRespDate(),
        );
        //$b = array();
        //foreach ($ticket->getAssignees() as $a) {
            //if (method_exists($a,"getFull"))
                //array_push($b, $a->getFull());
            //else
                //array_push($b, $a);
        //}
        //array_push($response, array('assigned_to' => $b));
        //unset($b);
        # get thread entries
        $tcount = $ticket->getThreadCount();
        $tcount += $ticket->getNumNotes();
        $types = array('M', 'R', 'N');
        $threadTypes = array('M'=>'message','R'=>'response', 'N'=>'note');
        $thread_entries = $ticket->getThread()->getEntries()->filter(array('type__in' => $types));
        //var_dump($thread_entries);die();
        $response['thread_count'] = $tcount;
        $response['thread_entries'] = [];
        foreach ($thread_entries as $tentry) {
            $response['thread_entries'][] = array(
                //'type' => $threadTypes[$tentry->getType()],
                'created_at' => $tentry->getCreateDate(),
                //'title' => $tentry->getTitle(),
                'user_name' => $tentry->getName()->getFull(),
                'body' => $tentry->getBody()->getClean(),
            );
        }
        $this->response(200, json_encode($response), $contentType="application/json");
    }
}

//Local email piping controller - no API key required!
class PipeApiController extends TicketApiController {

    //Overwrite grandparent's (ApiController) response method.
    function response($code, $resp) {

        //Use postfix exit codes - instead of HTTP
        switch($code) {
            case 201: //Success
                $exitcode = 0;
                break;
            case 400:
                $exitcode = 66;
                break;
            case 401: /* permission denied */
            case 403:
                $exitcode = 77;
                break;
            case 415:
            case 416:
            case 417:
            case 501:
                $exitcode = 65;
                break;
            case 503:
                $exitcode = 69;
                break;
            case 500: //Server error.
            default: //Temp (unknown) failure - retry
                $exitcode = 75;
        }

        //echo "$code ($exitcode):$resp";
        //We're simply exiting - MTA will take care of the rest based on exit code!
        exit($exitcode);
    }

    function  process() {
        $pipe = new PipeApiController();
        if(($ticket=$pipe->processEmail()))
           return $pipe->response(201, $ticket->getNumber());

        return $pipe->exerr(416, __('Request failed - retry again!'));
    }
}

?>
