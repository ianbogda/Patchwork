<?php

class extends agent_pStudio_reader
{
	function compose($o)
	{
		$a = @file_get_contents($this->realpath);

		if (false !== $a)
		{
			$b = @unserialize($a);

			if (false !== $b || $a === serialize(false))
			{
				$o->text = '<?php serialize(' . var_export($b, true) . ')';
			}
		}

		return $o;
	}
}