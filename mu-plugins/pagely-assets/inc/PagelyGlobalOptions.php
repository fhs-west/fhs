<?php

class PagelyGlobalOptions
{
    protected $ms;

    public function __construct($multiSite = null)
    {
        if ($multiSite === null)
            $multiSite = is_multisite();

        $this->ms = $multiSite;
    }

	public function get()
	{
		if ($this->ms) {
			$f = "get_site_option";
		} else {
			$f = "get_option";
		}

		return call_user_func_array($f, func_get_args());
	}

	public function update()
	{
		if ($this->ms) {
			$f = "update_site_option";
		} else {
			$f = "update_option";
		}

		return call_user_func_array($f, func_get_args());
	}


    public function add($name, $value, $dep = '', $autoload = 'yes')
    {
		if ($this->ms) {
			$f = "add_site_option";
		} else {
			$f = "add_option";
        }

        return $f($name, $value, $dep, $autoload);
    }

    public function delete($name)
    {
        if ($this->ms) {
			$f = "delete_site_option";
		} else {
			$f = "delete_option";
		}

        return $f($name);
    }
}
