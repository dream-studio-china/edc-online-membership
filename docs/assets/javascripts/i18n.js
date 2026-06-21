(function(){
var p = window.location.pathname;
var isZh = /\/zh(\/|$)/.test(p);

var enUrl, zhUrl;
if (isZh) {
    enUrl = p.replace('/zh/', '/');
    zhUrl = p;
} else {
    enUrl = p;
    var slash = p.indexOf('/', 1);
    zhUrl = (slash > 0)
        ? p.substring(0, slash) + '/zh' + p.substring(slash)
        : '/zh' + p;
}

function insert() {
    var h = document.querySelector('.md-header__inner');
    if (!h) { setTimeout(insert, 50); return; }
    var d = document.createElement('div');
    d.style.cssText = 'display:flex;align-items:center;gap:2px;margin-left:12px;font-size:.7rem;opacity:.7';
    d.innerHTML = '<a href="'+enUrl+'" style="color:inherit;text-decoration:none;padding:0 4px">EN</a>/<a href="'+zhUrl+'" style="color:inherit;text-decoration:none;padding:0 4px">中文</a>';
    h.appendChild(d);
}
insert();
})();
