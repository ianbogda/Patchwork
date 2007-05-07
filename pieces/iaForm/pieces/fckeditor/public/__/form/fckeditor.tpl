{***************************************************************************
 *
 *   Copyright : (C) 2006 Nicolas Grekas. All rights reserved.
 *   Email     : nicolas.grekas+patchwork@espci.org
 *   License   : http://www.gnu.org/licenses/gpl.txt GNU/GPL, see COPYING
 *
 *   This program is free software; you can redistribute it and/or modify
 *   it under the terms of the GNU General Public License as published by
 *   the Free Software Foundation; either version 2 of the License, or
 *   (at your option) any later version.
 *
 ***************************************************************************}
<!--
{*

This template plugs FCKeditor

You can pass it every HTML attribute you need (class, on*, ...), they will be used to define the element.

You can control it with the additional arguments:
- a$_caption_										: the caption of the element, with class="required" if needed
- a$_beforeError_	|default:g$inputBeforeError		: HTML code put at the beginning of an error message
- a$_afterError_	|default:g$inputAfterError		: HTML code put at the end of an error message
- a$autofocus										: set the focus on this element
- a$_format_		|default:g$inputFormat			: a string to format the output where ("=>" means "is replaced by"):
														  %0 => the caption,
														  %1 => the control,
														  %2 => the error message,
														  %% => %

*}

IF a$autofocus
	SET a$autofocus -->autofocus<!-- END:SET
END:IF

IF a$required
	SET a$required -->required<!-- END:SET
END:IF

SET a$id -->FiD{g+1$GLOBID}<!-- END:SET
SET a$class -->{a$class|default:a$type}<!-- END:SET

IF !a$title
	SET a$title
		-->{a$_caption_|replace:'<[^>]*>':''}<!--
	END:SET
END:IF


SET $CAPTION
	IF a$_caption_
		--><label for="{a$id}" class="{a$class}" onclick="return IlC(this)"><!--
		IF a$required --><span class="required"><!-- END:IF
		-->{a$_caption_}<!--
		IF a$required --></span><!-- END:IF
		--></label><!--
	END:IF
END:SET


SET $INPUT

	IF a$required --><span class="required"><!-- END:IF

	IF !g$_FCKEDITOR
		SET g$_FCKEDITOR -->1<!-- END:SET
		--><script type="text/javascript" src="{base:'fckeditor/js'}"></script><!--
	END:IF

	--><textarea {a$|htmlArgs:'type':'value'}>{a$value}</textarea><script type="text/javascript">/*<![CDATA[*/
lE=gLE({a$name|js}<!-- IF a$multiple -->,1<!-- END:IF -->)
if(lE){
lE.gS=function(){FCKeditorAPI.GetInstance({a$id|js}).UpdateLinkedField();return valid(this<!-- LOOP a$_valid -->,{$VALUE|js}<!-- END:LOOP -->)}
lE.cS=function(){return IcES([0<!-- LOOP a$_elements -->,{$name|js},{$onempty|js},{$onerror|js}<!-- END:LOOP -->],this.form)}
lE=new FCKeditor({a$id|js},(''+lE.style.width).indexOf('%')>0?lE.style.width:lE.offsetWidth,(''+lE.style.height).indexOf('%')>0?lE.style.height:lE.offsetHeight,{a$_toolbarSet|js},lE.value)
lE.BasePath={~|js}+'fckeditor/'
lE.Config.AutoDetectLanguage=false
lE.Config.DefaultLanguage={g$__LANG__|js}
<!-- LOOP a$_config -->
<!-- SET a$a -->{$VALUE|substr:0:1}<!-- END:SET -->
lE.Config[{$KEY|js}]=<!-- IF '[' == a$a -->{$VALUE|js}<!-- ELSE -->{$VALUE|js}<!-- END:IF -->
<!-- END:LOOP -->
lE.ReplaceTextarea()
}//]]></script ><!--

	IF a$required --></span><!-- END:IF

END:SET


SET $ERROR
	IF a$_errormsg -->{a$_beforeError_|default:g$inputBeforeError}<span class="errormsg">{a$_errormsg}</span>{a$_afterError_|default:g$inputAfterError}<!-- END:IF
END:SET


-->{a$_format_|default:g$inputFormat|echo:$CAPTION:$INPUT:$ERROR}
