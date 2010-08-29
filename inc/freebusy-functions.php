<?php

/**
 * Function to include which handles building a free/busy response to
 * be used in either the REPORT, response to a POST, or response to a
 * a freebusy GET request.
 */

include_once("RRule-v2.php");


function get_freebusy( $path_match, $range_start, $range_end ) {
  global $request;

  if ( !isset($range_start) || !isset($range_end) ) {
    $request->DoResponse( 400, 'All valid freebusy requests MUST contain a time-range filter' );
  }
  $params = array( ':path_match' => '^'.$path_match, ':start' => $range_start->UTC(), ':end' => $range_end->UTC() );
  $where = ' WHERE caldav_data.dav_name ~ :path_match ';
  $where .= 'AND rrule_event_overlaps( dtstart, dtend, rrule, :start, :end) ';
  $where .= "AND caldav_data.caldav_type IN ( 'VEVENT', 'VTODO' ) ";
  $where .= "AND (calendar_item.transp != 'TRANSPARENT' OR calendar_item.transp IS NULL) ";
  $where .= "AND (calendar_item.status != 'CANCELLED' OR calendar_item.status IS NULL) ";

  if ( $request->Privileges() != privilege_to_bits('all') ) {
    $where .= "AND (calendar_item.class != 'PRIVATE' OR calendar_item.class IS NULL) ";
  }


  $fbtimes = array();
  $sql = "SELECT caldav_data.caldav_data, calendar_item.rrule, calendar_item.transp, calendar_item.status, ";
  $sql .= "to_char(calendar_item.dtstart at time zone 'GMT',".iCalendar::SqlUTCFormat().") AS start, ";
  $sql .= "to_char(calendar_item.dtend at time zone 'GMT',".iCalendar::SqlUTCFormat().") AS finish ";
  $sql .= "FROM caldav_data INNER JOIN calendar_item USING(dav_id,user_no,dav_name)".$where;
  if ( isset($c->strict_result_ordering) && $c->strict_result_ordering ) $sql .= " ORDER BY dav_id";
  $qry = new AwlQuery( $sql, $params );
  if ( $qry->Exec("REPORT",__LINE__,__FILE__) && $qry->rows() > 0 ) {
    while( $calendar_object = $qry->Fetch() ) {
      $extra = '';
      if ( $calendar_object->status == 'TENTATIVE' ) {
        $extra = ';BUSY-TENTATIVE';
      }
      dbg_error_log( "REPORT", " FreeBusy: Not transparent, tentative or cancelled: %s, %s", $calendar_object->start, $calendar_object->finish );
      $ics = new vComponent($calendar_object->caldav_data);
      $expanded = expand_event_instances($ics, $range_start, $range_end);
      $expansion = $expanded->GetComponents( array('VEVENT','VTODO','VJOURNAL') );
      foreach( $expansion AS $k => $v ) {
        $dtstart = $v->GetProperty('DTSTART');
        $start_date = new RepeatRuleDateTime($dtstart->Value());
        $duration = $v->GetProperty('DURATION');
        $end_date = clone($start_date);
        $end_date->modify( $duration->Value() );
        $thisfb = $start_date->UTC() .'/'. $end_date->UTC() . $extra;
        array_push( $fbtimes, $thisfb );
      }
    }
  }

  $freebusy = new iCalComponent();
  $freebusy->SetType('VFREEBUSY');
  $freebusy->AddProperty('DTSTAMP', date('Ymd\THis\Z'));
  $freebusy->AddProperty('DTSTART', $range_start->UTC());
  $freebusy->AddProperty('DTEND', $range_end->UTC());

  sort( $fbtimes );
  foreach( $fbtimes AS $k => $v ) {
    $text = explode(';',$v,2);
    $freebusy->AddProperty( 'FREEBUSY', $text[0], (isset($text[1]) ? array('FBTYPE' => $text[1]) : null) );
  }


  $result = new iCalComponent();
  $result->VCalendar();
  $result->AddComponent($freebusy);

  return $result->Render();
}

