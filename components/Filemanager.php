<?php

namespace dpodium\filemanager\components;

use Yii;
use yii\helpers\Html;
use dpodium\filemanager\models\Files;

class Filemanager {

    const TYPE_FULL_PAGE = 1; // upload from filemanager module
    const TYPE_MODAL = 2; // upload from pop-up modal

    public static function renderEditUploadedBar($fileId, $objectUrl, $filename, $fileType) {
        $src = $objectUrl . $filename;
        $file = static::getThumbnail($fileType, $src, "20px", "30px");
        $content_1 = Html::tag('h6', $filename, ['class' => 'separator-box-title']);
        $content_2 = Html::tag('div', Html::a(Yii::t('filemanager', 'Edit'), ['/filemanager/files/update', 'id' => $fileId], ['target' => '_blank']), ['class' => 'separator-box-toolbar']);
        $content_3 = Html::tag('div', $file . $content_1 . $content_2, ['class' => 'separator-box-header']);
        $html = Html::tag('div', $content_3, ['class' => 'separator-box']);

        return $html;
    }

    public static function getThumbnail($fileType, $src, $height = '', $width = '') {
        $thumbnailSize = \Yii::$app->controller->module->thumbnailSize;
        if ($fileType == 'image') {
            $options = (!empty($height) && !empty($width)) ? ['height' => $height, 'width' => $width] : ['height' => "{$thumbnailSize[1]}px", 'width' => "{$thumbnailSize[0]}px"];
            return Html::img($src, $options);
        }

        $availableThumbnail = ['archive', 'audio', 'code', 'excel', 'movie', 'pdf', 'powerpoint', 'text', 'video', 'word', 'zip'];
        $type = explode('/', $fileType);
        $faClass = 'fa-file-o';
        $fontSize = !empty($height) ? $height : "{$thumbnailSize[1]}px";        

        if (in_array($type[0], $availableThumbnail)) {
            $faClass = "fa-file-{$type[0]}-o";
        } else if (in_array($type[1], $availableThumbnail)) {
            $faClass = "fa-file-{$type[1]}-o";
        }

        return Html::tag('div', Html::tag('i', '', ['class' => "fa {$faClass}", 'style' => "font-size: $fontSize"]), ['class' => 'fm-thumb', 'style' => "height: $height; width: $width"]);
    }

    public static function getFile($keyValue, $key = 'file_id', $thumbnail = false, $tag = false) {
        $model = new \Yii::$app->controller->module->models['files'];
        $fileObject = $model->find()->where([$key => $keyValue])->one();

        $file = [];
        if ($fileObject) {
            foreach ($fileObject as $attribute => $value) {
                $file['info'][$attribute] = $value;
            }

            $src = $fileObject->object_url . $fileObject->src_file_name;
            if ($thumbnail) {
                $src = $fileObject->object_url . $fileObject->thumbnail_name;
            }
            $file['img'] = Html::img($src);
        }

        if ($tag && isset($fileObject->filesRelationships)) {
            foreach ($fileObject->filesRelationships as $relationship) {
                if (isset($relationship->tag)) {
                    $file['tag'][$relationship->tag->tag_id] = $relationship->tag->value;
                }
            }
        }

        return $file;
    }

}
