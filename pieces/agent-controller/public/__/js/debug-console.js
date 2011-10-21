function Z()
{
    scrollTo(0, window.innerHeight || document.documentElement.scrollHeight);
}

function classifyEvent(token, type, data)
{
    var target = 'requests', div = document.createElement('DIV');

    div.className = 'event';

    switch (type)
    {
    case 'php-error':
        target = 'php-errors';
        if (data.data.level)
        {
            type = data.data.level.split('/'); // TODO: report more info about data.data.level
            if (!(type[0] & type[1])) div.className += ' silenced', target = 'php-silenced-errors';
            data.data.level = undefined; // Tag as do not display
        }
        break;

    case 'client-dump':
    case 'server-dump': target = 'E'; break;
    }

    var state = {
        depth: 0,
        buffer: []
    };

    // TODO: use token, type, data.time and data.mem, data.patchwork and data.globals when available

    div.innerHTML = htmlizeEvent(data.data, data.__refs);

    document.getElementById(target).appendChild(div);
}

function htmlizeEvent(data, refs)
{
    var iRefs = {},
        depth,
        counter,
        buffer = [],
        span = document.createElement('SPAN');

    refs = refs || {};
    for (counter in refs)
        for (depth in refs[counter])
            iRefs[refs[counter][depth]] = counter;

    depth = 1;
    counter = data._ ? parseInt(data._) - 1 : 0;

    function escape(s)
    {
        span.innerText = span.textContent = s;
        return span.innerHTML;
    }

    function push(data, tags, title)
    {
        if (title && title.length) tags += '" title="' + title.join(', ');
        buffer.push('<span class="' + tags + '">' + escape(data) + '</span>');
    }

    function htmlizeData(data, tags, title, toggle)
    {
        var i, e, t, b;

        ++counter;
        title = title || [];
        tags = tags || '';
        toggle = toggle || 'compact';

        if (refs[counter]) push('#' + counter, 'ref target');
        else if (iRefs[counter]) push('r' + iRefs[counter], 'ref handle');
        else if (iRefs[-counter]) push('R' + iRefs[-counter], 'ref alias');

        switch (true)
        {
        case null === data: data = 'null';
        case true === data:
        case false === data:
        default:
            push(data, 'const' + tags, title);
            break;

        case 'string' === typeof data:
            if ('' === data)
            {
                title.push('Empty string');
                push('', 'string empty' + tags, title);
                return;
            }

            i = data.indexOf('`');
            if (-1 == i) data = ['u', data];
            else data = [data.substr(0, i), data.substr(i+1)];
            i = data[0].charAt(data[0].length - 1);

            switch (i)
            {
                case 'R':
                case 'r': return;
                case 'n': push(data[1], 'const' + tags, title); return;
                case 'b': tags += ' bin'; title.push('Binary');
                case 'u': tags = 'string' + tags;
            }

            i = parseInt(data[0]);

            title.push('Length: ' + (0 < i ? i : data[1].length));

            data = data[1].split(/\r?\n/g);

            if (data.length > 1)
            {
                for (e = 0; e < data.length; ++e)
                {
                    buffer.push('\n' + new Array(depth + 2).join(' '));
                    push(data[e], tags + ('' === data[e] ? ' empty' : ''), title);
                }
            }
            else push(data[0], tags, title);

            if (0 < i) push('...', 'cut');

            break;

        case 'object' === typeof data:
            b = ['[', ']'];
            t = data['_'] ? data['_'].split(':') : [0];

            if (undefined === t[1]) {}
            else if (undefined === t[2])
            {
                t.isObject = 1;
                if ('stdClass' !== t[1]) push(t[1], 'class');
                b = ['{', '}'];
            }
            else if ('resource' === t[1])
            {
                t.isResource = 1;
                push('resource:' + t[2], 'class');
            }
            else if ('array' === t[1])
            {
                t.isArray = 1;
            }

            e = 0;
            for (i in data) if ('_' !== i && '__cutBy' !== i && '__refs' !== i && 2 === ++e) break;

            if (!e)
            {
                buffer.push(b[0]);
                if (data.__cutBy) push('...', 'cut', ['Cut by ' + data.__cutBy]);
                buffer.push(b[1]);
                return;
            }

            depth += 2;
            buffer.push('<span class="array-' + toggle + '">');
            buffer.push(b[0]);
            buffer.push(('compact' == toggle ? '<a onclick="arrayToggle(this)"> ⊞ </a>' : ''));
            toggle = 1 === e ? 'expanded' : 'compact';

            for (i in data)
            {
                if ('_' === i || '__cutBy' === i || '__refs' === i) continue;
                if (undefined === data[i] && ++counter) continue;

                title = [];
                tags = ' key';
                buffer.push('\n' + new Array(depth).join(' '));
                e = parseInt(i);

                if ('' + e !== i)
                {
                    e = i.indexOf(':');
                    e = -1 === e ? ['', i] : [i.substr(0, e), i.substr(e+1)];

                    if (t.isObject)
                    {
                        if ('' === e[0])
                        {
                            title.push('Public property');
                            tags += ' public';
                        }
                        else switch (e[0].charAt(e[0].length - 1))
                        {
                        case '`': title.push('Public property'); tags += ' public'; break;
                        case '*': title.push('Protected property'); tags += ' protected'; break;
                        default:
                            title.push('Private property from class ' + e[0].replace(/^[^`]*`/, ''));
                            tags += ' private';
                            break;
                        }
                    }

                    e = e[0].replace(/[^`]+$/, '') + e[1];
                }

                t[0] = counter;
                counter = -1;
                htmlizeData(e, tags, title);
                counter = t[0];
                e = buffer[buffer.length-1];
                buffer[buffer.length-1] = e.substr(0, e.length-7);
                push(' ⇨ ', 'arrow');
                buffer.push('</span>');
                htmlizeData(data[i], '', [], toggle);
                buffer.push(', ');
            }

            if (data.__cutBy)
            {
                buffer.push('\n' + new Array(depth).join(' '));
                push('...', 'cut', ['Cut by ' + data.__cutBy]);
                buffer.push(', ');
            }

            depth -= 2;
            buffer[buffer.length - 1] = '';
            buffer.push('\n' + new Array(depth).join(' '));
            buffer.push(b[1]);
            buffer.push('</span>');

            break;
        }
    }

    htmlizeData(data);

    return buffer.join('');
}

function arrayToggle(a)
{
    var s = a.parentNode;

    if ('array-compact' == s.className)
    {
        a.innerHTML = ' ⊟ ';
        s.className = 'array-expanded';
    }
    else
    {
        a.innerHTML = ' ⊞ ';
        s.className = 'array-compact';
    }
}