
<table>
  <tr>
    <th></th>
    <th>Date</th>
    <th>From</th>
    <th>Subject</th>
    <th></th>
  </tr>
  <? foreach($current_user->private_messages as $message) { ?>
    <tr>
      <td><?= (!$message->read_at) ? 'new' : '' ?></td>
      <td><?= click_date_format($message->created_at) ?></td>
      <td><?= htmlentities($message->sender->profile->screen_name) ?></td>
      <td><?= htmlentities($message->subject) ?></td>
      <td><a href="<?= htmlentities(view_private_message_url($message)) ?>">view</a>
      </td>
    </tr>
  <? } ?>
</table>

