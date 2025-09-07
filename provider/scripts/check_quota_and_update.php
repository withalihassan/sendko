.fail(function(xhr){
  var text = xhr.responseText || xhr.statusText || 'Request failed';
  // If response is long HTML (stack trace), show first 400 chars for sanity
  if (text.length > 400) text = text.substr(0,400) + '...';
  $status.html('<pre style="white-space:pre-wrap; font-size:12px; color:#a00;">' + $('<div>').text(text).html() + '</pre>');
});
