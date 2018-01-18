<?php
$online_agents = osTicketSession::get_active_staff();
if (empty($online_agents)) {
    return;
}
$usernames = array_map(function($agent) {
    return $agent['firstname'].' '.$agent['lastname'];
}, $online_agents);
?>
<div class="online-agents">
    <h3><?php echo __('Online agents');?>:</h3>
    <?php echo join($usernames, ', ');?>
</div>
