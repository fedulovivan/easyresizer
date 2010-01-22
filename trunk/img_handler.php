<?php

if(!defined('DS')) define('DS', DIRECTORY_SEPARATOR);

$image = new ImageHandler( $_SERVER['REQUEST_URI'] );

// Echoing image, or error to browser
echo $image->get();

// Comment this line on production for security purposes
$image->print_errors();

/**
 * Easyresize image handler class
 * @version $Id$
 * @package Easyresizer
 * @author Fedulov Ivan <fedulov.ivan@gmail.com>
 */
class ImageHandler {

    const IMG_PORTRAIT  = 1;
    const IMG_LANDSCAPE = 2;

    private
        /**
         * Флаг, не возникло ли ошибок
         * при обработке
         * @var boolean
         */
        $_valid                     = true,
        /**
         * Полностью строка запроса,
         * переданная конструктору
         * @var string
         */
        $_query_string              = null,
        /**
         * Только имя картинки
         * @var string
         */
        $_image_filename            = null,
        /**
         * Полный путь и имя оригинала запрашиваемой картинки в ФС
         * @var string
         */
        $_original_full_filename    = null,
        /**
         * Параметры для ресайза:
         *   x_constraint - integer, смотрите README
         *   y_constraint - integer, смотрите README
         *   crop_area    - string, смотрите README
         * @var array
         */
        $_resize_params             = array(),
        /**
         * Допустимые расширения файла
         * @var string
         */
        $_allowed_ext               = array('jpeg','jpg','gif','png'),
        /**
         * Путь в ФС к папке со скриптом
         * @var string
         */
        $_scripts_root              = null,
        /**
         * Исходная строка параметров ресайза
         * @var string
         */
        $_raw_param_string          = null,
        /**
         * Ошибки хэндлера
         * Для отладочных целей
         * @var array
         */
        $_errors                    = array(),
        /**
         * Имя папки, куда складывается кэш картинок
         * (относительно $_scripts_root)
         */
        $_cache                     = 'img_cache',
        /**
         * Имя папки, где хранятся исходные картинки
         * (относительно $_scripts_root)
         */
        $_sources                   = 'img';

    /**
     * Конструктор
     * @param string $request_uri запрос пользователя
     */
    public function  __construct( $request_uri ) {

        $this->_query_string = trim($request_uri, "/");
        //$this->_scripts_root = realpath( dirname(__FILE__) . DS . '..' );
        $this->_scripts_root = realpath( dirname(__FILE__) );
        $parts = explode('/', $this->_query_string);
        $this->_image_filename    = array_pop($parts);
        $this->_raw_param_string  = array_pop($parts);

        $cache_dir = $this->_scripts_root . DS . $this->_cache;
        if(!is_writable( $cache_dir )) {
            if(!chmod( $cache_dir, 0755 )) {
                $this->_valid = false;
                $this->_errors[] = sprintf( "Directory %s is`t writable", $cache_dir );
            }
        }
        
        if($this->_valid)
            $this->_valid = $this->_check_filename();

        if($this->_valid)
            $this->_valid = $this->_check_params();
        
        if($this->_valid)
            $this->_valid = $this->_check_original();

        return $this->_valid;
    }

    /**
     * Запрос картинки
     * если для указанных параметров оригинал не найден выводит 404ю ошибку
     *
     * Quering image.
     * If for the privided params original file haven`t found outputs 404 error
     *
     * return void
     */
    public function  get() {
        $this->_valid ? $this->_get_image() : $this->_404_error();
    }

    /**
     * Распечатка ошибок класса, если они есть
     * @return <type>
     */
    public function print_errors() {
        if(empty ($this->_errors)) return ;
        if(!headers_sent()) header("HTTP/1.0 500 Internal Server Error");
        echo '<pre>';
        echo "Got errors:\n";
        foreach ($this->_errors AS $err) echo "\t- {$err}";
        echo '</pre>';
    }

    /**
     * Проверка корректности имени файла(в т.ч. и допутимого расширения)
     *
     * Checking if filename is correct(both with extension)
     * 
     * @return boolean
     */
    private function _check_filename() {
        if(empty($this->_image_filename)) {
            $this->_errors[] = "Image filename is empty";
            return false;
        }
        if(!preg_match('/^.*\.(' . implode('|', $this->_allowed_ext) . ')$/i', $this->_image_filename)) {
            $this->_errors[] = sprintf("Filename %s has not allowed extension", $this->_image_filename);
            return false;
        }
        return true;
    }

    /**
     * Checking, if params string is correct
     *
     * Проверка, корректна ли строка параметров
     *
     * @return boolean
     */
    private function _check_params() {
        if(empty ($this->_raw_param_string)) {
            $this->_errors[] = "Params string is empty";
            return false;
        }
        $matches = null;
        if(!preg_match('/^(\d{1,4})_(\d{1,4})(_([s|m|e]))?$/i', $this->_raw_param_string, $matches)) {
            $this->_errors[] = sprintf("Params string %s isn`t match",$this->_raw_param_string);
            return false;
        }
        $this->_resize_params = array(
            'x_constraint'  => $matches[1],
            'y_constraint'  => $matches[2],
            'crop_area'     => !empty($matches[4]) ? $matches[4] : 'm'
        );
        if($this->_resize_params['x_constraint'] == 0 && $this->_resize_params['y_constraint'] == 0 ) {
            $this->_errors[] = "Both X and Y constraints are empty";
            return false;
        }
        return true;
    }

    /**
     * Прверка на наличие оригинальной картинки
     * 
     * Check, if original image is present
     *
     * @return boolean
     */
    private function _check_original() {
        $file_path = $this->_scripts_root . DS . $this->_sources . DS . $this->_image_filename;
        if( is_file($file_path) ) {
            $this->_original_full_filename = $file_path;
            return true;
        } else {
            $this->_errors[] = sprintf("Original file %s doesn`t exist", $file_path);
            return false;
        }
    }

    /**
     * Проверка, на то влезит ли новая картинка в память
     * 
     * @param integer $w высота картинки
     * @param integer $h ширина картинки
     * 
     * return boolean
     */
    private function _check_allowed_memory( $w, $h, $bits ) {
        $limit  = 1024 * 1024 * intval( ini_get('memory_limit') );
        $needed = $w * $h * $bits;
        $checked = $limit > $needed;
        if(!$checked) {
            $this->_errors[] = sprintf("Can`t allocate %s bytes in %s avaible memory", $needed, $limit);
        }
        return $checked;
    }

    /**
     * Outputing 404 error
     *
     * вывод 404й ошибки
     *
     * return void
     */
    private function _404_error( $error = null ) {
        header("HTTP/1.0 404 Not Found");
        echo !empty($error) ? $error : "HTTP/1.0 404 Not Found";
    }

    /**
     * Главный метод. Основываясь на параметрах делает ресайз исходного изображения
     * если необходимо, то вместе с обрезанием.
     * В результате картинка складывается в папку с кэшем и одновременно отправляется в браузер
     *
     * return void
     */
    private function _get_image() {
        
        $src_data = getimagesize($this->_original_full_filename);

        $src_width  = $orig_width  = $src_data[0];
        $src_height = $orig_height = $src_data[1];

        // Ориентация исходной картинки
        $src_orientation = $src_width > $src_height ? self::IMG_LANDSCAPE : self::IMG_PORTRAIT;
        // Соотношение сторон исходной
        $src_prop = $src_width / $src_height;
        
        switch ($src_data[2]) {
            case IMAGETYPE_JPEG:
                $src_resource = imagecreatefromjpeg($this->_original_full_filename);
                break;
            case IMAGETYPE_GIF:
                $src_resource = imagecreatefromgif($this->_original_full_filename);
                break;
            case IMAGETYPE_PNG:
                $src_resource = imagecreatefrompng($this->_original_full_filename);
                break;
        }

        // Координаты левого верхнего угла целевого изображения (всегда равны нулю)
        $dst_x = 0;
        $dst_y = 0;

        // Координаты левого верхнего угла исходного изображения
        // (ненулевые, в случе если происходит crop не из "начала картинки")
        $src_x = 0;
        $src_y = 0;

        // Имеем ограничение только по высоте
        if( empty($this->_resize_params['y_constraint']) ) {
            $dst_width  = $this->_resize_params['x_constraint'];
            $dst_height = floor( $dst_width / $src_prop );
        // Имеем ограничение только по ширине
        } elseif( empty($this->_resize_params['x_constraint']) ) {
            $dst_height  = $this->_resize_params['y_constraint'];
            $dst_width   = floor( $src_prop * $dst_height );
        // Результат ограничен в обоих размерностях (теперь важен параметр "crop_area")
        } else {
            $dst_width   = $this->_resize_params['x_constraint'];
            $dst_height  = $this->_resize_params['y_constraint'];

            $dst_orientation = $dst_width > $dst_height ? self::IMG_LANDSCAPE : self::IMG_PORTRAIT;
            $dst_prop = $dst_width / $dst_height;

            // 1. Одиниковая ориентация
            $same_ori = $dst_orientation == $src_orientation;
            // 2. Конечная картинка имеет портретную ориентацию
            $dst_is_landscape  = $dst_orientation == self::IMG_LANDSCAPE;
            $dst_is_portrait   = $dst_orientation == self::IMG_PORTRAIT;
            // 3.Конечная картинка имеет отличное значение пропорций
            // т.е. была 3x4 а новая 2x6
            $overed_prop = $dst_prop < $src_prop;

            // Основываясь на трех параметрах определяем область
            // в исходной картинке
            // @todo Возможно стоит оптимизировать эту пляску логики
            if( !$same_ori && $dst_is_landscape ) {
                $src_height = floor( $src_width / $dst_prop );
                $src_y = $this->_get_crop_coord($orig_height, $src_height);
            } elseif( $same_ori && $dst_is_landscape ) {
                if($overed_prop) {
                    $src_width   = floor( $dst_prop * $src_height );
                    $src_x = $this->_get_crop_coord($orig_width, $src_width);
                } else {
                    $src_height  = floor( $src_width / $dst_prop );
                    $src_y = $this->_get_crop_coord($orig_height, $src_height);
                }
            } elseif( !$same_ori && $dst_is_portrait ) {
                $src_width  = floor( $dst_prop * $src_height );
                $src_x = $this->_get_crop_coord($orig_width, $src_width);
            } elseif( $same_ori && $dst_is_portrait ) {
                if($overed_prop) {
                    $src_width   = floor( $dst_prop * $src_height );
                    $src_x = $this->_get_crop_coord($orig_width, $src_width);
                } else {
                    $src_height  = floor( $src_width / $dst_prop );
                    $src_y = $this->_get_crop_coord($orig_height, $src_height);
                }
            }
        }            

        if( !$this->_check_allowed_memory( $dst_width, $dst_height, $src_data['bits'] ) ){
            $this->_404_error();
            return false;
        }

        $dst_resource = imagecreatetruecolor($dst_width, $dst_height);

        // Transparency for png
        if( $src_data[2] == IMAGETYPE_PNG ) {
            imagealphablending($dst_resource, false);
            imagesavealpha($dst_resource,true);
            $tc = imagecolorallocatealpha($dst_resource, 255, 255, 255, 127);
            imagefilledrectangle($dst_resource, 0, 0, $dst_width, $dst_height, $tc);
        }

        // Transparency for gif
        if( $src_data[2] == IMAGETYPE_GIF ) {
            $cnt = imagecolorstotal($src_resource);
            imagetruecolortopalette($src_resource,true,$cnt);
            imagepalettecopy($dst_resource,$src_resource);
            $tc = imagecolortransparent($src_resource);
            imagefill($dst_resource,0,0,$tc);
            imagecolortransparent($dst_resource, $tc);
        }
        
        if( function_exists('imagecopyresampled') ) {
            imagecopyresampled( 
                $dst_resource, $src_resource,
                $dst_x,     $dst_y,
                $src_x,     $src_y,
                $dst_width, $dst_height,
                $src_width, $src_height
            );
        } else {
            imagecopyresized(
                $dst_resource, $src_resource,
                $dst_x,     $dst_y,
                $src_x,     $src_y,
                $dst_width, $dst_height,
                $src_width, $src_height
            );
        }

        // Создаем директорию
        $new_dir = $this->_scripts_root . DS . $this->_cache . DS . $this->_raw_param_string;
        if( !is_dir($new_dir) ) {
            mkdir( $new_dir );
            chmod( $new_dir, 0777 );
        }

        $new_img_filepath = $new_dir . DS . $this->_image_filename;

        switch ($src_data[2]) {
            case IMAGETYPE_JPEG:
                imagejpeg( $dst_resource, $new_img_filepath, 80 );
                header("Content-type: {$src_data['mime']}");
                imagejpeg($dst_resource, null, 80);
                break;
            case IMAGETYPE_GIF:
                imagegif( $dst_resource, $new_img_filepath );
                header("Content-type: {$src_data['mime']}");
                imagegif($dst_resource);
                break;
            case IMAGETYPE_PNG:
                imagepng( $dst_resource, $new_img_filepath, 0 );
                header("Content-type: {$src_data['mime']}");
                imagepng($dst_resource, null, 0, PNG_ALL_FILTERS);
                break;
        }
        
        imagedestroy($src_resource);
        imagedestroy($dst_resource);
    }

    /**
     *
     * @param integer $orig исходный размер
     * @param integer $dst результирующий размер
     * @return integer
     */
    private function _get_crop_coord( $orig, $dst ) {
        switch ( strtolower($this->_resize_params['crop_area']) ) {
            case 's':
                return 0;
                break;
            case 'm':
                return ($orig - $dst)/2;
                break;
            case 'e':
                return $orig - $dst;
                break;
            default:
                return 0;
        }
    }


}

