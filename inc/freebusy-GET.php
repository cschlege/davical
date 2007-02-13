<?php
require_once("iCalendar.php");
include_once("RRule.php");

/**
* We need to allow GET of start & finish so we can have a consistent regression test result set.  And it might be useful
* to people as well...
*/
if ( isset($_GET['start']) && preg_match( '/^[12][0-9]{3}(0[0-9]|1[012])[0123][0-9]T[0-2][0-9]([0-5][0-9]){2}$/', $_GET['start'] )) {
  $start = $_GET['start'];
}
else {
  $start = date( "Ymd\THis", time() - (86400 * 30) );
}
if ( isset($_GET['finish']) && preg_match( '/^[12][0-9]{3}(0[0-9]|1[012])[0123][0-9]T[0-2][0-9]([0-5][0-9]){2}$/', $_GET['finish'] )) {
  $finish = $_GET['finish'];
}
else {
  $finish = date( "Ymd\THis", time() + (86400 * 200) );
}


if ( isset($request->by_email) ) {
  $where = "WHERE caldav_data.user_no = $request->user_no ";
}
else {
  $where = "WHERE caldav_data.user_no = $request->user_no AND caldav_data.dav_name ~ ".qpg("^".$request->path)." ";
}
$where .= "AND (dtend >= '$start'::timestamp with time zone OR calculate_later_timestamp('$start'::timestamp with time zone,dtend,rrule) >= '$start'::timestamp with time zone) ";
$where .= "AND dtstart <= '$finish'::timestamp with time zone ";
$where .= "AND caldav_data.caldav_type IN ( 'VEVENT', 'VFREEBUSY' ) ";
$where .= "AND (calendar_item.transp != 'TRANSPARENT' OR calendar_item.transp IS NULL) ";
$where .= "AND (calendar_item.status != 'CANCELLED' OR calendar_item.status IS NULL) ";
$where .= "AND (calendar_item.class != 'PRIVATE' OR calendar_item.class IS NULL OR get_permissions($session->user_no,caldav_data.user_no) ~ 'A') "; // Must have 'all' permissions to see confidential items

$busy = array();
$busy_tentative = array();
$sql = "SELECT caldav_data.caldav_data, calendar_item.rrule, calendar_item.transp, calendar_item.status, ";
$sql .= "to_char(calendar_item.dtstart at time zone 'GMT',".iCalendar::SqlDateFormat().") AS start, ";
$sql .= "to_char(calendar_item.dtend at time zone 'GMT',".iCalendar::SqlDateFormat().") AS finish ";
$sql .= "FROM caldav_data INNER JOIN calendar_item USING(user_no, dav_name) $where ORDER BY dtstart, dtend";
// echo $sql. "\n";
$qry = new PgQuery( $sql );
if ( $qry->Exec("freebusy",__LINE__,__FILE__) && $qry->rows > 0 ) {
  while( $calendar_object = $qry->Fetch() ) {
    if ( $calendar_object->transp != "TRANSPARENT" ) {
      switch ( $calendar_object->status ) {
        case "TENTATIVE":
          dbg_error_log( "REPORT", " FreeBusy: tentative appointment: %s, %s", $calendar_object->start, $calendar_object->finish );
          $busy_tentative[] = $calendar_object;
          break;

        case "CANCELLED":
          // Cancelled events are ignored
          break;

        default:
          dbg_error_log( "REPORT", " FreeBusy: Not transparent, tentative or cancelled: %s, %s", $calendar_object->start, $calendar_object->finish );
          $busy[] = $calendar_object;
          break;
      }
    }
  }
}
$freebusy = iCalendar::iCalHeader();
$freebusy .= sprintf("BEGIN:VFREEBUSY\nDTSTAMP:%s\nDTSTART:%s\nDTEND:%s\n", date('Ymd\THis\Z'), $start, $finish);

foreach( $busy_tentative AS $k => $v ) {
  $start = new iCalDate($v->start);
  $duration = $start->DateDifference($v->finish);
  if ( $v->rrule != "" ) {
    $rrule = new RRule( $start, $v->rrule );
    while ( $date = $rrule->GetNext() ) {
      if ( ! $date->GreaterThan($start) ) continue;
      if ( $date->GreaterThan($finish) ) break;
      $freebusy .= sprintf("FREEBUSY;FBTYPE=BUSY-TENTATIVE:%s/%s\n", $date->Render('Ymd\THis'), $duration );
    }
  }
  else {
    $freebusy .= sprintf("FREEBUSY;FBTYPE=BUSY-TENTATIVE:%s/%s\n", $start->Render('Ymd\THis'), $duration );
  }
}

foreach( $busy AS $k => $v ) {
  $start = new iCalDate($v->start);
  $duration = $start->DateDifference($v->finish);
  if ( $v->rrule != "" ) {
    $rrule = new RRule( $start, $v->rrule );
    while ( $date = $rrule->GetNext() ) {
      if ( ! $date->GreaterThan($start) ) continue;
      if ( $date->GreaterThan($finish) ) break;
      $freebusy .= sprintf("FREEBUSY:%s/%s\n", $date->Render('Ymd\THis'), $duration );
    }
  }
  else {
    $freebusy .= sprintf("FREEBUSY:%s/%s\n", $start->Render('Ymd\THis'), $duration );
  }
}

$freebusy .= "END:VFREEBUSY\n";
$freebusy .= iCalendar::iCalFooter();
$request->DoResponse( 200, $freebusy, 'text/calendar' );
// Won't return from that


?>