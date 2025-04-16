<?php

  class TableRender
  {

    public   $color_map = array();
    public   $color_cfg = array();
    public   $cols_ptx  = array();  // position x for each column
    public   $width =  0;
    public   $height = 0;
    public   $row_height = 24;
    public   $font = 'arial.ttf';
    public   $cell_padding  = array(12, 4, 12, 4); // left, top, right, bottom
    protected $image = false;

    function __construct(int $width, int $height, $back_color = 'cyan')
    {
        $img = imagecreate($width, $height);
        $this->image = $img;
        $this->width = $width;
        $this->height = $height;

        $cmap = array(); // same as pallete
        $cmap['magenta']  = array(250,  91, 250);
        $cmap['red']      = array(250,  91, 91);
        $cmap['yellow']   = array(250, 255, 91);
        $cmap['cyan']     = array( 00,  91, 91);
        $cmap['green']    = array( 90, 255, 91);
        $cmap['white']    = array(255, 255, 255);
        $this->font = getcwd().'/'.$this->font;


        // first color allocate must be background
        if (is_array($back_color))
           $back_color = imagecolorallocate($img, ...$back_color);  // direct assign color
        elseif (is_string($back_color) && isset($cmap[$back_color]))
           $this->color_map[$back_color] = imagecolorallocate($img, ...$cmap[$back_color]); // using name

        // allocating additional colors
        foreach ($cmap as $cname => $rgb)
         if (!isset($this->color_map[$cname]))
             $this->color_map[$cname] = imagecolorallocate($img, ...$rgb);

        $this->color_cfg = array('background' => $back_color, 'lines' => 'white', 'text' => 'yellow');
    }

    function __destruct()  {
        imagedestroy($this->image);
    }

    protected function PickColor($cname) {
        $color = $cname;
        if (is_string($cname) && isset($this->color_cfg[$cname]))
            $color = $this->color_cfg[$cname];

        if (is_string($color) && isset($this->color_map[$color]))
            $color = $this->color_map[$color];
        return $color;
    }

    public function DrawBack() { // clear all and draw grid
        $back  = $this->PickColor('background');
        $linec = $this->PickColor('lines');
        imagefilledrectangle ($this->image, 0, 0, $this->width, $this->height, $back);
        imagerectangle ($this->image, 0, 0, $this->width - 1, $this->height - 1, $linec);
    }

    public function DrawGrid($y_min = 0, $y_max = 10000) {
        $y_max = min($y_max, $this->height - 1);
        $linec = $this->PickColor('lines');
        foreach ($this->cols_ptx as $x)
            imageline($this->image, $x, $y_min, $x, $y_max, $linec);
        $y = $y_min;
        while ($y < $y_max) {
           imageline($this->image, 0, $y, $this->width - 1, $y, $linec); // rows always from left to right
           $y += $this->row_height;
        }
    }


    public function DrawText($col, $row, $text, $color = 'text', $font = false, $font_size = 12, $opts = []) { // simple mode, 0 col is left, 0 row is upper

        if (!$font)
          $font = $this->font;

        $color = $this->PickColor($color);
        $px = $this->cols_ptx[$col] + $this->cell_padding[0]; // left origination
        $py = $this->row_height * (1 + $row) - $this->cell_padding[3]; // bottom origination
        $angle = 0;
        if (!is_string($text))
           $text = var_export($text, true);
        imagettftext ($this->image, $font_size, $angle, $px, $py, $color, $font, strval($text));
    }

    public function GetImage() {
        return $this->image;
    }

    public function SetColumns() {  // set column X points
        $args = func_get_args();
        $this->cols_ptx = array();
        foreach ($args as $arg) {
           if (is_array($arg))
             $this->cols_ptx = $arg;
           elseif (is_numeric($arg))
             $this->cols_ptx []= $arg;
        }
        return count($this->cols_ptx);
    }

    public function SavePNG($filename) {
        $fd = fopen($filename, 'wb');
        if ($fd) {
           imagepng($this->image, $fd);
           fclose($fd);
           return true;
        }
        return $fd;
    }
    public function StrokePNG() {
        imagepng($this->image);
    }


  }

?>