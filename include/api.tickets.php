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
            //using 'attachments' will result in osticket manipulating the attachments, so we use 'files' instead
            "files" => array("*" =>
                array("name", "content")
            ),
            "deptId" => array(),
            "message", "ip", "priorityId", "status"
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

        //fetch dynamic departments
        if (isset($data['deptId']) && is_array($data['deptId'])) {
            foreach ($data['deptId'] as $deptId) {
                $dept = Dept::lookup($deptId);
                if ($dept) {
                    $supported['deptId'][] = $deptId;
                }
            }
        }
        else {
            unset($supported['deptId']);
            $supported[] = 'deptId';
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

        //attachments
        if (array_key_exists('files', $data)) {
            $data['attachments'] = $this->saveAttachments($data['files'], $errors);
            unset ($data['files']);
        }

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

        TicketForm::ensureDynamicDataView();
        $query = TicketModel::objects();
        $tickets = [];
        if (array_key_exists('email', $request)) {
            $query
                ->filter(array('user__emails__address' => $request['email']));
        }

        if (array_key_exists('status', $request)) {
            $query
                ->filter(array('status__state' => $request['status']));
        }

        if (array_key_exists('deptId', $request)) {
            if (!is_array($request['deptId'])) {
                $request['deptId'] = [$request['deptId']];
            }
            $query
                ->filter(array('dept_id__in' => $request['deptId']));
        }

        $tickets = $query->values(
            'ticket_id', 'number', 'created', 'isanswered', 'source', 'status_id',
            'status__state', 'status__name', 'cdata__subject', 'dept_id',
            'dept__name', 'dept__ispublic', 'user__default_email__address', 'lastupdate'
        );

        $count_tickets = $tickets;
        $count = $count_tickets->count();
        
        $tickets->order_by('-created');
        $tickets->annotate(array(
            'thread_count' => SqlAggregate::COUNT('thread__entries'),
        ));

        $tickets = $tickets->all();

        $response = array(
            'total' => $count,
            'tickets' => $tickets,
        );

        $this->response(200, json_encode($response));
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

        TicketForm::ensureDynamicDataView();
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
        //$threadTypes = array('M'=>'message','R'=>'response', 'N'=>'note');
        $thread_entries = $ticket->getThread()->getEntries()->filter(array('type__in' => $types));
        //var_dump($thread_entries);die();
        $response['thread_count'] = $tcount;
        $response['thread_entries'] = [];
        foreach ($thread_entries as $tentry) {
            $poster = $tentry->getName();
            if (is_object($poster)) {
                $poster = $poster->getFull();
            }
            //var_dump($tentry->getAttachmentUrls());
            $attachments = [];
            foreach ($tentry->getAttachments()->all() as $k => $attachment) {
                $attachments[] = [
                    'id' => $attachment->file->id,
                    'filename' => $attachment->file->name,
                    'download_url' => $attachment->file->getDownloadUrl(),
                    //'key' => $attachment->file->key,
                ];
            }
            //var_dump($tentry->getName());
            //echo '----';
            $response['thread_entries'][] = array(
                //'type' => $threadTypes[$tentry->getType()],
                'created_at' => $tentry->getCreateDate(),
                //'title' => $tentry->getTitle(),
                'user_name' => $poster,
                'from_staff' => !is_null($tentry->getStaff()),
                'body' => $tentry->getBody()->getClean(),
                'attachments' => $attachments,
            );
        }
        $this->response(200, json_encode($response), $contentType="application/json");
    }

    function getAttachment($ticket_number, $attachment_id, $format) {
        if(!($key=$this->requireApiKey())) {
            return $this->exerr(401, __('API key not authorized'));
        }

        $request = $this->getRequest($format);

        if (!array_key_exists('email', $request)) {
            $this->response(400, json_encode(array('error' => 'missing email parameter')));
            return;
        }

        TicketForm::ensureDynamicDataView();
        # Checks for existing ticket with that number
        $id = Ticket::getIdByNumber($ticket_number, $request['email']);
        if ($id <= 0) {
            return $this->response(404, __("Ticket not found"));
        }

        # Load ticket and send response
        $ticket = Ticket::lookup($id);

        //cycle through ticket's thread entries to see if the requested attachment is among the ticket's thread entries attachments
        $types = array('M', 'R', 'N');
        //$threadTypes = array('M'=>'message','R'=>'response', 'N'=>'note');
        $thread_entries = $ticket->getThread()->getEntries()->filter(array('type__in' => $types));

        $file = AttachmentFile::lookup((int) $attachment_id);

        foreach ($thread_entries as $tentry) {
            foreach ($tentry->getAttachments()->all() as $k => $attachment) {
                if ($attachment->file->id == $file->getId()) {
                    $response = [
                        'filename' => $file->getName(),
                        'data' => base64_encode($file->getData()),
                    ];
                    $this->response(200, json_encode($response), $contentType="application/json");
                }
            }
        }
        return $this->response(404, __("File not found"));
    }

    function replyToTicket($ticket_number, $format) {
        if(!($key=$this->requireApiKey())) {
            return $this->exerr(401, __('API key not authorized'));
        }

        $request = $this->getRequest($format);

        if (!array_key_exists('email', $request)) {
            $this->response(400, json_encode(array('error' => 'missing email parameter')));
            return;
        }

        if (!array_key_exists('message', $request)) {
            $this->response(400, json_encode(array('error' => 'missing message parameter')));
            return;
        }

        # Checks for existing ticket with that number
        $id = Ticket::getIdByNumber($ticket_number, $request['email']);
        if ($id <= 0) {
            return $this->response(404, __("Ticket not found"));
        }

        # Load ticket and send response
        $ticket = Ticket::lookup($id);
        $alert = true;
        $vars = [
            'userId' => $ticket->getUser()->getId(),
            'threadId' => $ticket->getThread()->getId(),
            'message' => nl2br($request['message']),
            'source' => 'API',
        ];

        $errors = [];
        //attachments
        if (array_key_exists('files', $request)) {
            $vars['cannedattachments'] = $this->saveAttachments($request['files'], $errors);
        }

        if (empty($errors)) {
            $reply_response = $ticket->postMessage($vars, $errors, $alert);
        }
        //var_dump($errors);die();
        //var_dump($reply_response);die();

        if (empty($errors)) {
            $this->response(200, json_encode([]), $contentType="application/json");
        }
        else {
            $this->response(500, json_encode($errors), $contentType="application/json");
        }
    }

    function saveAttachments($attachments, &$errors) {
        //var_dump($attachments);die();
        $attachment_ids = [];
        foreach ($attachments as $attachment) {
            //$attachment_ids[$attachment['name']] = $attachment['content'];
            //continue;
            $file = [
                'name' => $attachment['name'],
                'data' => $attachment['content'],
                'encoding' => 'base64',
            ];
            $f = AttachmentFile::create($file);

            if ($f) {
                $attachment_ids[] = $f->getId();
            }
            else {
                $errors[] = "cant upload file ".$attachment['name'];
            }
            //if (!($F = AttachmentFile::upload($file)))
        }
        return $attachment_ids;
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
