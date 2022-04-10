<?php

// STEPS
// Delete all mignatures and pictures not loaded by the site
// Run the script
// Run reGenerate Thumbnails Advanced plugin

mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);

date_default_timezone_set('Europe/Brussels'); // Fix container default timezone // TODO: improve this
$db_host = getenv('WORDPRESS_DB_HOST');
$db_user = getenv('WORDPRESS_DB_USER');
$db_password = getenv('WORDPRESS_DB_PASSWORD');
$db_name = getenv('WORDPRESS_DB_NAME');
$wp_content_path = '../../';

// echo "db_host: " . $db_host . "\n";
// echo "db_user: " . $db_user . "\n";
// echo "db_password: " . $db_password . "\n";
// echo "db_name: " . $db_name . "\n";

$sqliConn = new mysqli($db_host, $db_user, $db_password, $db_name);

// [0] Check connection
if ($sqliConn->connect_errno) {
    throw new RuntimeException('mysqli connection error: ' . $sqliConn->connect_error);
}

$sqliConn->set_charset('utf8mb4');
if ($sqliConn->errno) {
    throw new RuntimeException('mysqli error: ' . $sqliConn->error);
}

// [1] Get site URL
$geSiteUrlQuery = "
	SELECT option_value
	FROM wp_options
	WHERE option_name='siteurl';
";

$siteUrlStmt = $sqliConn->prepare($geSiteUrlQuery);

if (!$siteUrlStmt) {
    throw new RuntimeException('Statment error: ' . $siteUrlStmt);
}

if (!$siteUrlStmt->execute()) {
    throw new RuntimeException('Statment error (' . $siteUrl . '): ' . $siteUrlStmt);
}

$result = $siteUrlStmt->get_result();
$site_url = $result->fetch_assoc()['option_value'];



// [2] Check integrity 
// All attachment post need to have these meta_key in wp_postmeta and inversely
// '_wp_attached_file', 
// '_wp_attachment_backup_sizes',  
// '_wp_attachment_metadata',  
// '_thumbnail_id'

// [2-1] Get last meta_id index of wp_postmeta
$getLastMetaIdQuery = "SELECT MAX(meta_id) AS meta_id_max FROM wp_postmeta;";

$lastMetaIdStmt = $sqliConn->prepare($getLastMetaIdQuery);

if (!$lastMetaIdStmt) {
    throw new RuntimeException('Statment error: ' . $lastMetaIdStmt);
}

if (!$lastMetaIdStmt->execute()) {
    throw new RuntimeException('Statment error (' . $postsUsingAttachment . '): ' . $lastMetaIdStmt);
}

$lastMetaId = $lastMetaIdStmt->get_result()->fetch_assoc()['meta_id_max'] + 1;


$getPostsAttachmentQuery = "
    SELECT ID AS post_id, guid AS url
    FROM wp_posts
    WHERE post_type='attachment' AND guid LIKE '%wp-content/uploads%';
";

$postsAttachmentStmt = $sqliConn->prepare($getPostsAttachmentQuery);

if (!$postsAttachmentStmt) {
    throw new RuntimeException('Statment error: ' . $postsAttachmentStmt);
}

if (!$postsAttachmentStmt->execute()) {
    throw new RuntimeException('Statment error (' . $postsUsingAttachment . '): ' . $postsAttachmentStmt);
}

$postsAttachment = $postsAttachmentStmt->get_result();

while ($postAttachment = $postsAttachment->fetch_assoc()) {
    $attachmentUrl = $postAttachment['url'];
    $attachmentFilePath = str_replace($site_url . '/wp-content/uploads/', '', $attachmentUrl);

    $getPostMetaAttachedFileQuery = "
        SELECT meta_id
        FROM wp_postmeta
        WHERE post_id='" . $postAttachment['post_id'] . "' AND meta_key='_wp_attached_file';
    ";

    $postMetaAttachedFileStmt = $sqliConn->prepare($getPostMetaAttachedFileQuery);

    if (!$postMetaAttachedFileStmt) {
        throw new RuntimeException('Statment error: ' . $postMetaAttachedFileStmt);
    }

    if (!$postMetaAttachedFileStmt->execute()) {
        throw new RuntimeException('Statment error (' . $postUsingAttachment . '): ' . $postMetaAttachedFileStmt);
    }

    $postMetaAttachedFile = $postMetaAttachedFileStmt->get_result();

    if (!$postMetaAttachedFile->fetch_assoc()['meta_id']) {
        $insertPostMetaAttachedFileQuery = "
            INSERT INTO wp_postmeta (meta_id, post_id, meta_key, meta_value)
            VALUES (" . $lastMetaId . ", " . $postAttachment['post_id'] . ", '_wp_attached_file',  '" . $attachmentFilePath . "');
        ";

        $insertPostMetaAttachedFileStmt = $sqliConn->prepare($insertPostMetaAttachedFileQuery);

        if (!$insertPostMetaAttachedFileStmt) {
            throw new RuntimeException('Statment error: ' . $insertPostMetaAttachedFileStmt);
        }

        if (!$insertPostMetaAttachedFileStmt->execute()) {
            throw new RuntimeException('Statment error (' . $postUsingAttachment . '): ' . $insertPostMetaAttachedFileStmt);
        }

        $lastMetaId += 1;
    } else {
        $updatePostMetaAttachedFileQuery = "
            UPDATE wp_postmeta
            SET meta_value='" . $attachmentFilePath . "' 
            WHERE post_id='" . $postAttachment['post_id'] . "' AND meta_key='_wp_attached_file';
        ";

        $updatePostMetaAttachedFileStmt = $sqliConn->prepare($updatePostMetaAttachedFileQuery);

        if (!$updatePostMetaAttachedFileStmt) {
            throw new RuntimeException('Statment error: ' . $updatePostMetaAttachedFileStmt);
        }

        if (!$updatePostMetaAttachedFileStmt->execute()) {
            throw new RuntimeException('Statment error (' . $postUsingAttachment . '): ' . $updatePostMetaAttachedFileStmt);
        }
    }

    // while ($postMetaAttachedFile = $postMetaAttachedFile->fetch_assoc()) {
    //     $updatePostMetaAttachedFileQuery = "
    //         UPDATE wp_postmeta
    //         SET meta_value='" . $attachmentFilePath . "' 
    //         WHERE meta_id='" . $postMetaAttachedFile['meta_id'] . "' AND meta_key='_wp_attached_file';
    //     ";

    //     $updatePostMetaAttachedFileStmt = $sqliConn->prepare($updatePostMetaAttachedFileQuery);

    //     if (!$updatePostMetaAttachedFileStmt) {
    //         throw new RuntimeException('Statment error: ' . $updatePostMetaAttachedFileStmt);
    //     }

    //     if (!$updatePostMetaAttachedFileStmt->execute()) {
    //         throw new RuntimeException('Statment error (' . $postUsingAttachment . '): ' . $updatePostMetaAttachedFileStmt);
    //     }
    // }
}

// List all uploads used in posts
// $getPostsUsingAttachmentQuery = "
// 	SELECT post_content
// 	FROM wp_posts
// 	WHERE post_type='post' AND post_content LIKE '%wp-content/uploads%';
// ";

// $postsUsingAttachmentStmt = $sqliConn->prepare($getPostsUsingAttachmentQuery);

// if (!$postsUsingAttachmentStmt) {
//     throw new RuntimeException('Statment error: ' . $postsUsingAttachmentStmt);
// }

// if (!$postsUsingAttachmentStmt->execute()) {
//     throw new RuntimeException('Statment error (' . $postsUsingAttachment . '): ' . $postsUsingAttachmentStmt);
// }

// $result = $postsUsingAttachmentStmt->get_result();
// $uploads_used_in_posts = [];

// while ($post = $result->fetch_assoc()) {
//     $attachmentUrlRegex = '/"' . str_replace('/', '\/', $site_url) . '\/wp-content\/uploads\/[\w\.\d\-_\/]+"/m';

//     $post_content = $post['post_content'];

//     $match_count = preg_match($attachmentUrlRegex, $post_content, $matches, PREG_OFFSET_CAPTURE, 0);

//     if ($match_count === 0 || $match_count === false) {
//         continue;
//     }

//     array_push(
//         $uploads_used_in_posts,
//         ...array_map(
//             fn($upload) => end(
//                 explode(
//                     '/',
//                     str_replace('"', '', $upload[0])
//                 )
//             ),
//             $matches
//         )
//     );
// }


// [2] Get files in uploads/
$uploads_path = $wp_content_path . '/uploads/';
function getDirContents($dir, &$results = array()) {
    $files = scandir($dir);

    foreach ($files as $key => $value) {
        $path = $dir . DIRECTORY_SEPARATOR . $value;

        $is_thumbnail = preg_match("/.+\d+x\d+\.\w+/", $value) > 0;

        if ($is_thumbnail) {
            continue;
        }

        if (!is_dir($path)) {
            $results[] = [
                'fullPath' => $path,
                'fileName' => $value,
            ];
        } else if ($value != "." && $value != "..") {
            getDirContents($path, $results);
        }
    }

    return $results;
}

$files = array_map(fn($file) => $file['fileName'], getDirContents($uploads_path));
$filesPath = array_map(fn($file) => str_replace($uploads_path . '/', '', $file['fullPath']), getDirContents($uploads_path));


// [4] Find uploads used but does not exist in uploads/
// $uploads_used_in_posts
// $files

// [3] Update WP attachments guid and file attach path with existing files in uploads/
$getAttachmentPathQuery = "
    SELECT ID AS post_id, guid AS url
    FROM wp_posts
    WHERE post_type='attachment' AND guid LIKE '%wp-content/uploads%';
";

$attachmentsPathStmt = $sqliConn->prepare($getAttachmentPathQuery);

if (!$attachmentsPathStmt) {
    throw new RuntimeException('Statment error: ' . $attachmentsPathStmt);
}

if (!$attachmentsPathStmt->execute()) {
    throw new RuntimeException('Statment error (' . $attachmentsPath . '): ' . $attachmentsPathStmt);
}

$result = $attachmentsPathStmt->get_result();

while ($fileUrl = $result->fetch_assoc()) {
    $file = end(explode('/', $fileUrl['url']));
    
    if (in_array($file, $files)) {
        $fileIndex = array_search($file, $files);

        $filePath = $filesPath[$fileIndex];

        $index = strpos($fileUrl['url'], '/wp-content/uploads/');

        $urlEnd = substr($fileUrl['url'], $index);

        $newUrl = str_replace($urlEnd, '/wp-content/uploads/' . $filePath, $fileUrl['url']);

        // Update attachment GUID
        $setAttachmentUrlQuery = "
            UPDATE wp_posts
            SET guid='" . $newUrl . "'
            WHERE ID='" . $fileUrl['post_id'] . "';
        ";

        $setAttachmentUrlStmt = $sqliConn->prepare($setAttachmentUrlQuery);

        if (!$setAttachmentUrlStmt) {
            throw new RuntimeException('Statment error: ' . $setAttachmentUrlStmt);
        }

        if (!$setAttachmentUrlStmt->execute()) {
            // TODO: test this because it does not throw any error when fail to update file
            throw new RuntimeException("An error has occured when trying to update attachment n°" . $fileUrl['post_id']);
        }

        echo "Attachment (" . $fileUrl['post_id'] . ') ' . $urlEnd . " patched !" . "\n";


        $attachmentAttachedFilePath = str_replace('/wp-content/uploads/', '', $urlEnd);
        // Update attachment _wp_attached_file value
        $updateAttachmentAttachedFileQuery = "
            UPDATE wp_postmeta
            SET meta_value='" . $attachmentAttachedFilePath . "'
            WHERE post_id='" . $fileUrl['post_id'] . "' AND meta_key='_wp_attached_file';
        ";

        $updateAttachmentAttachedFile = $sqliConn->prepare($updateAttachmentAttachedFileQuery);

        if (!$updateAttachmentAttachedFile) {
            throw new RuntimeException('Statment error: ' . $updateAttachmentAttachedFile);
        }

        if (!$updateAttachmentAttachedFile->execute()) {
            // TODO: test this because it does not throw any error when fail to update file
            throw new RuntimeException("An error has occured when trying to update file attached for attachment n°" . $fileUrl['post_id']);
        }

        echo "Attachment (post_id: " . $fileUrl['post_id'] . ') ' . $attachmentAttachedFilePath . " attached file patched !" . "\n";
    }
}


// [4] Insert uploads not present in DB but exist in uploads/
$getPostsAttachmentQuery = "
    SELECT guid AS url
    FROM wp_posts
    WHERE post_type='attachment' AND guid LIKE '%wp-content/uploads%';
";

$getPostsAttachmentStmt = $sqliConn->prepare($getPostsAttachmentQuery);

if (!$getPostsAttachmentStmt) {
    throw new RuntimeException('Statment error: ' . $getPostsAttachmentStmt);
}

if (!$getPostsAttachmentStmt->execute()) {
    throw new RuntimeException('Statment error (' . $attachmentsPath . '): ' . $getPostsAttachmentStmt);
}

$postsAttachment = $getPostsAttachmentStmt->get_result();

$attachments = [];
while ($postAttachment = $postsAttachment->fetch_assoc()) {
    $postAttachmentUrl = $postAttachment['url'];
    $attachedFile = end(explode('/', $postAttachmentUrl));

    array_push($attachments, $attachedFile);
}

$missing_files_in_db = array_filter(
    $filesPath,
    static function($filePath) {
        global $attachments;
        $file_name = end(explode('/', $filePath));
        
        return !in_array($file_name, $attachments);
    }
);

$getfirstUserIdQuery = "SELECT MIN(ID) AS first_user_id FROM wp_users;";

$firstUserIdStmt = $sqliConn->prepare($getfirstUserIdQuery);

if (!$firstUserIdStmt) {
    throw new RuntimeException('Statment error: ' . $firstUserIdStmt);
}

if (!$firstUserIdStmt->execute()) {
    throw new RuntimeException('Statment error (' . $postsUsingAttachment . '): ' . $firstUserIdStmt);
}

$firstUserId = $firstUserIdStmt->get_result()->fetch_assoc()['first_user_id'];



foreach ($missing_files_in_db as $missing_file_path) {
    $getLastPostIdQuery = "SELECT MAX(ID) AS last_post_id FROM wp_posts;";

    $lastPostIdStmt = $sqliConn->prepare($getLastPostIdQuery);

    if (!$lastPostIdStmt) {
        throw new RuntimeException('Statment error: ' . $lastPostIdStmt);
    }

    if (!$lastPostIdStmt->execute()) {
        throw new RuntimeException('Statment error (' . $postsUsingAttachment . '): ' . $lastPostIdStmt);
    }

    $lastPostId = $lastPostIdStmt->get_result()->fetch_assoc()['last_post_id'] + 1;


    // Post values
    $now = time();
    $post_date = date("Y-m-d H:m:s", $now);
    $post_date_gmt = gmdate("Y-m-d H:m:s", $now);
    $post_name = end(explode('/', $missing_file_path));
    $post_title = explode('.', $post_name)[0];
    $missing_file_url = $site_url . '/wp-content/uploads/' . $missing_file_path;
    $missing_file_mimetype = mime_content_type($wp_content_path . '/uploads/' . $missing_file_path);


    $getDefaultPingOptionQuery = "
        SELECT option_value
        FROM wp_options
        WHERE option_name LIKE '%default_ping_status%';
    ";
    
    $getDefaultPingOptionStmt = $sqliConn->prepare($getDefaultPingOptionQuery);

    if (!$getDefaultPingOptionStmt) {
        throw new RuntimeException('Statment error: ' . $getDefaultPingOptionStmt);
    }

    if (!$getDefaultPingOptionStmt->execute()) {
        throw new RuntimeException('Statment error (' . $attachmentsPath . '): ' . $getDefaultPingOptionStmt);
    }

    $defaultPingOption = $getDefaultPingOptionStmt->get_result()->fetch_assoc()['option_value'];

    $insertMissingUploadsFileToPostsQuery = "
        INSERT INTO wp_posts (ID, post_author, post_date, post_date_gmt, post_content, post_title, post_excerpt, post_status, comment_status, ping_status, post_password, post_name, to_ping, pinged, post_modified, post_modified_gmt, post_content_filtered, post_parent, guid, menu_order, post_type, post_mime_type, comment_count)
        VALUES ($lastPostId, $firstUserId, '$post_date', '$post_date_gmt', '', '$post_title', '', 'inherit', '$defaultPingOption', 'open', '', '$post_name', '', '', '$post_date', '$post_date_gmt', '', 0, '$missing_file_url', 0, 'attachment', '$missing_file_mimetype', 0);
    ";

    $insertMissingUploadsFileToPostsStmt = $sqliConn->prepare($insertMissingUploadsFileToPostsQuery);

    if (!$insertMissingUploadsFileToPostsStmt) {
        throw new RuntimeException('Statment error: ' . $insertMissingUploadsFileToPostsStmt);
    }

    if (!$insertMissingUploadsFileToPostsStmt->execute()) {
        throw new RuntimeException('Statment error (' . $attachmentsPath . '): ' . $insertMissingUploadsFileToPostsStmt);
    }

    $getInsertedMissingUploadsFilePostQuery = "
        SELECT ID AS post_id
        FROM wp_posts
        WHERE guid='$missing_file_url';
    ";

    $getInsertedMissingUploadsFilePostStmt = $sqliConn->prepare($getInsertedMissingUploadsFilePostQuery);

    if (!$getInsertedMissingUploadsFilePostStmt) {
        throw new RuntimeException('Statment error: ' . $getInsertedMissingUploadsFilePostStmt);
    }

    if (!$getInsertedMissingUploadsFilePostStmt->execute()) {
        throw new RuntimeException('Statment error (' . $attachmentsPath . '): ' . $getInsertedMissingUploadsFilePostStmt);
    }

    $insertedMissingUploadsFilePostId = $getInsertedMissingUploadsFilePostStmt->get_result()->fetch_assoc()['post_id'];
    
    $insertMissingUploadsFilePostMetaQuery = "
        INSERT INTO wp_postmeta (meta_id, post_id, meta_key, meta_value)
        VALUES ($lastMetaId, $insertedMissingUploadsFilePostId, '_wp_attached_file',  '$missing_file_path');
    ";

    $insertMissingUploadsFilePostMetaStmt = $sqliConn->prepare($insertMissingUploadsFilePostMetaQuery);

    if (!$insertMissingUploadsFilePostMetaStmt) {
        throw new RuntimeException('Statment error: ' . $insertMissingUploadsFilePostMetaStmt);
    }

    if (!$insertMissingUploadsFilePostMetaStmt->execute()) {
        throw new RuntimeException('Statment error (' . $attachmentsPath . '): ' . $insertMissingUploadsFilePostMetaStmt);
    }

    $lastMetaId += 1;
}


// [5] Clean orphans wp_postmeta keys

$getPostMetasPostIdQuery = "
    SELECT post_id
    FROM wp_postmeta
    WHERE meta_key IN (
        '_wp_attached_file',
        '_wp_attachment_backup_sizes',
        '_wp_attachment_metadata',
        '_thumbnail_id'
    );
";

$getPostMetasPostIdStmt = $sqliConn->prepare($getPostMetasPostIdQuery);

if (!$getPostMetasPostIdStmt) {
    throw new RuntimeException('Statment error: ' . $getPostMetasPostIdStmt);
}

if (!$getPostMetasPostIdStmt->execute()) {
    throw new RuntimeException('Statment error (' . $postsUsingAttachment . '): ' . $getPostMetasPostIdStmt);
}

$postMetsPostIdResult = $getPostMetasPostIdStmt->get_result();

$postMetaPostIds = [];
while ($postMetssPostId = $postMetsPostIdResult->fetch_assoc()) {
    array_push($postMetaPostIds, $postMetssPostId['post_id']);
}

$uniquePostMetaPostIds = array_unique($postMetaPostIds);

$gostPostIds = [];
foreach ($uniquePostMetaPostIds as $metaPostId) {
    $getPostQuery = "
        SELECT post_author
        FROM wp_posts
        WHERE ID=$metaPostId;
    ";

    $getPostStmt = $sqliConn->prepare($getPostQuery);

    if (!$getPostStmt) {
        throw new RuntimeException('Statment error: ' . $getPostStmt);
    }

    if (!$getPostStmt->execute()) {
        throw new RuntimeException('Statment error (' . $postsUsingAttachment . '): ' . $getPostStmt);
    }

    if (!$getPostStmt->get_result()->fetch_assoc()) {
        array_push($gostPostIds, $metaPostId);
    }
}

foreach ($gostPostIds as $gostPostId) {
    $deletePostQuery = "
        DELETE
        FROM wp_postmeta
        WHERE post_id=$gostPostId;
    ";

    $deletePostStmt = $sqliConn->prepare($deletePostQuery);

    if (!$deletePostStmt) {
        throw new RuntimeException('Statment error: ' . $deletePostStmt);
    }

    if (!$deletePostStmt->execute()) {
        throw new RuntimeException('Statment error (' . $postsUsingAttachment . '): ' . $deletePostStmt);
    }
}

mysqli_close($sqliConn);
