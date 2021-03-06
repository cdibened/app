/**
 * Help for the recent activity card.
 */
beestat.component.modal.help_recent_activity = function() {
  beestat.component.modal.apply(this, arguments);
};
beestat.extend(beestat.component.modal.help_recent_activity, beestat.component.modal);

beestat.component.modal.help_recent_activity.prototype.decorate_contents_ = function(parent) {
  parent.appendChild($.createElement('p').innerHTML('View up to the past 7 days of thermostat activity in 5-minute resolution. This can help you visualize daily runtime trends and identify acute system issues. Compare to the Home IQ System & Follow Me charts.'));

  var table = $.createElement('table');
  table.style('color', beestat.style.color.blue.base);
  parent.appendChild(table);

  var tr;
  var td;

  tr = $.createElement('tr');
  table.appendChild(tr);

  td = $.createElement('td');
  td.setAttribute('valign', 'top');
  tr.appendChild(td);
};

beestat.component.modal.help_recent_activity.prototype.get_title_ = function() {
  return 'Recent Activity - Help';
};
