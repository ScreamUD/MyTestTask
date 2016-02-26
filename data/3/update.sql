create index stored_for_reporting on dc_calc_orders (stored_for_reporting);

alter table dc_denied_party_orders add generated tinyint default 0;