#!/usr/bin/php
<?php
/*
	A CLI program to embed picasa tags from .picasa.ini into image files.
	
	Description:
	Picasa (desktop version) doesn't write tags to PNG or any
	other files except JPEG. Instead, it stores the tag information in
	.picasa.ini files (per-directory). This program allows you to read these
	tags from .picasa.ini and write them to individual files in EXIF, IPTC
	and/or XMP formats. It will do this recursively for every directory.
	It can also optionally convert the images to some other format.

	Copyright:
		(C) 2013  Alexander Shaduri <ashaduri 'at' gmail.com>
	License: Zlib

	Version 1.0.2

	Requirements:
		exiv2 or higher (tested with exiv2-0.23);
		OR exiftool 9.x or higher (tested with exiftool-9.43);
		ImageMagick's convert (optional) for format conversion.
		php5.1 or higher (tested with php-5.3.15 under Linux).
		php-gd extension.

	Picasa ini files must be generated by Picasa 3.9.12 or newer (tested with
	version 3.9.137 (Build 69) under Windows 7).

	Tag support by various programs:
	* Picasa does not support any tags in PNG files (probably any files except JPEG).
	* Picasa will read both MWG and WPG faces, but will write only MWG.
	* Windows Photo Gallery supports only WPG.
	* DigiKam (3.5.0) supports reading Picasa-written MWG (but not exiftool- or
	exiv2-written files) and WPG. Writing is in development.
*/


error_reporting(E_ALL);



// ----------- Configuration


/// |-separated list of image file extensions, used in PCRE regexp.
define("IMAGE_FILE_EXTENSIONS", "jpeg|jpg|png");

/// PCRE regular expression to match the filenames against.
define("IMAGE_FILE_REGEXP", '/\\.(' . IMAGE_FILE_EXTENSIONS . ')$/i');

/// exiftool binary (can be an absolute path)
define("EXIFTOOL_BINARY", "exiftool");

/// exiv2 binary (can be an absolute path)
define("EXIV2_BINARY", "exiv2");

/// convert (ImageMagick) binary (can be an absolute path)
define("CONVERT_BINARY", "convert");

/// Output format. Leave empty to retain the original format.
define("OUTPUT_FORMAT", "png");

/// Output format quality (for jpeg) or compression level (for png) if converting.
define("OUTPUT_FORMAT_QUALITY", "9");


/// Whether to use exiftool or exiv2. I've encountered problems with
/// exiv2 (XMP toolkit saying the property doesn't exist), so default to exiftool.
define("EXIFTOOL_MODE", true);

/// Some EXIF tags may only contain ASCII
define("WRITE_EXIF", false);

/// Some IPTC tags may only contain ASCII
define("WRITE_IPTC", false);

/// XMP uses unicode. Note that face tags are supported only by XMP.
define("WRITE_XMP", true);

/// Write XMP-MWG standard format for faces (used by Picasa)
define("WRITE_XMP_MWG", true);

/// Write XMP MS Windows Photo Gallery (WPG) extension format for faces (used by Windows)
define("WRITE_XMP_WPG", true);

/// SkyDrive errors out on duplicate faces during upload.
define("WRITE_DUPLICATE_FACES", false);

/// It's better not to write unknown faces since they are usually duplicates and
/// SkyDrive errors out on duplicate faces during upload.
define("WRITE_UNKNOWN_FACES", false);

/// Append keywords to caption for applications that show only captions.
define("APPEND_KEYWORDS_TO_CAPTION", false);


/// Setting this to true makes the output a little more verbose.
define("VERBOSE_OUTPUT", false);


// ----------- End of Configuration



/// Return a 2D array of the filenames in the following format:
/// array(dir1 => filename_array, dir2 => filename_array, ...)
/// The directories all start with $dir, while the filenames don't
/// contain any path components.
/// $file_filter_rx is a PCRE regular expression to include only
/// certain types of files.
function rec_scandir($dir, $file_filter_rx, array &$files)
{
	if ( ($handle = opendir($dir)) ) {
		$entries = array();
		while ( ($file = readdir($handle)) !== false ) {
			if ( $file != ".." && $file != "." ) {
				$entry = $dir . DIRECTORY_SEPARATOR . $file;
				if (is_dir($entry)) {
					rec_scandir($entry, $file_filter_rx, $files);
				} else if (preg_match($file_filter_rx, $file)) {
					$entries[] = $file;
				}
			}
		}
		closedir($handle);
		$files[$dir] = $entries;
	}
}


/*
/// Load contacts.xml into an associative (face_id => face_name) array.
function load_contacts($contacts_file)
{
	$contact_names = array();  // id => name
	try {
		$xmldoc = new DOMDocument();
		$xmldoc->load($contacts_file);

		$contacts = $xmldoc->getElementsByTagName("contact");
		foreach ($contacts as $contact) {
			$contact_id = $contact->getAttribute("id");
			$contact_name = $contact->getAttribute("name");
		}
	} catch (Exception $e) {
		var_dump($e);
		return NULL;
	}
	return $contact_names;
}
*/


/// A simple rectangle
class Rectangle {

	/// Constructor
	public function Rectangle($x_arg, $y_arg, $w_arg, $h_arg)
	{
		$this->x = $x_arg;
		$this->y = $y_arg;
		$this->w = $w_arg;
		$this->h = $h_arg;
	}

	/// Return a rectangle with pixel values (as opposed to normalized ones).
	public function pixel_sized($image_px_w, $image_px_h)
	{
		$rect = clone($this);
		$rect->x = round($rect->x * $image_px_w);
		$rect->y = round($rect->y * $image_px_h);
		$rect->w = round($rect->w * $image_px_w);
		$rect->h = round($rect->h * $image_px_h);
		return $rect;
	}

	/// Since picasa has problems with long float representations,
	/// make sure the coordinates are rounded strings.
	public function rounded()
	{
		$rect = clone($this);
		$rect->x = round_xmp($rect->x);
		$rect->y = round_xmp($rect->y);
		$rect->w = round_xmp($rect->w);
		$rect->h = round_xmp($rect->h);
		return $rect;
	}

	public $x = 0.;  ///< X coordinate (top left)
	public $y = 0.;  ///< Y coordinate (top left)
	public $w = 0.;  ///< Width
	public $h = 0.;  ///< Height
}



/// Face (rectangular area) information
class FaceInfo {
	public $face_id = "";  ///< Face ID
	public $name = "";  ///< Face name
	public $rect;  ///< Rectangle in normalized, [0, 1] coordinates. Multiply x and w by image width, y and h by image height to receive pixel coordinates.
};



/// Complete information to be written to the image tags
class ImageInfo {
	public $caption = "";  ///< A string, image caption
	public $keywords = array();  // Array of strings
	public $faces = array();  ///< Array of FaceInfo
}



/// Round a double parameter to the number of digits supported by XMP.
function round_xmp($value)
{
	// Picasa won't load a face tag if the coordinate precision is more than 9.
	// The standard float precision of Picasa is 6, so that's what we'll use
	// here for safety.
	$prec = 6;
	return sprintf("%.{$prec}f", round($value, $prec));
}



/// Parse ini file (apparently, parse_ini_file() has some problems with
/// Picasa format.
function app_parse_ini($file)
{
	$index = 0;
	$values = array();
	$f = fopen($file, 'r');
	if (!$f) {
		return NULL;
	}
	while (!feof($f)) {
		$line = trim(fgets($f));
		if (preg_match('/^\[(.*?)\]$/', $line, $matches)) {
			$index = $matches[1];
			continue;
		}
		$parts = explode('=', $line, 2);
		if (count($parts) < 2)
			continue;
		if (!isset($values[$index])) {
			$values[$index] = array();
		}
		$values[$index][$parts[0]] = $parts[1];
	}
	fclose($f);
	return $values;
}



/// Returns array of FaceInfo with face names not set.
function parse_faces_line($faces_ini_value)
{
	$values = explode(';', $faces_ini_value);
	$faces = array();
	foreach($values as $face_value) {
		// format:
		// rect64(566c646a9d1dd72b),4e52c83bb73ac894
		// See https://gist.github.com/fbuchinger/1073823 for format description
		if (preg_match('/^rect64\(([^)]+)\),([0-9a-f]+)/i', $face_value, $matches)) {
			$face = new FaceInfo();
			$face->face_id = $matches[2];
			$face->rect = decode_rect_info($matches[1]);
			$faces[] = $face;
		}
	}
	return $faces;
}



/// Decode the rect64() argument into a Rectangle with normalized coordinates.
function decode_rect_info($rect64_value)
{
	$rect64_value = str_pad($rect64_value, 16, "0", STR_PAD_LEFT);  // leading 0s may not be present in the input.
	$x = hexdec(substr($rect64_value,0,4)) / 65536;
	$y = hexdec(substr($rect64_value,4,4)) / 65536;
	$right = hexdec(substr($rect64_value,8,4)) / 65536;
	$bottom = hexdec(substr($rect64_value,12,4)) / 65536;
	$w = $right - $x;
	$h = $bottom - $y;

	return new Rectangle($x, $y, $w, $h);
}



/// Perform string serialization according to XMP rules.
/// This is only needed in structures.
function escape_xmp($str)
{
	return preg_replace('/([|,{}\\[\\]])/', '|\\1', $str);
}



/// Double-quote a string for usage as exiv2 string argument to add/set command.
function escape_exiv_string($str)
{
	// Since we shell-escape these strings, no need for double-backslash or anything.
	return '"' . str_replace('"', '\\"', $str) . '"';
}



/// Return ini tags in array(file => array(ImageInfo), ...) format
function load_ini_image_infos($picasa_file)
{
	$face_ids = array();

	if (WRITE_UNKNOWN_FACES) {
		// We can leave the unknown person tags so that they can be easily searched for.
		$face_ids["ffffffffffffffff"] = "Unknown";
	}

	$ini_sections = app_parse_ini($picasa_file);
	if ($ini_sections === NULL) {
		return NULL;
	}

	// The contacts section format is:
	// [Contacts2]
	// 1e85e978a76ab144=Fname1 Lname1;;
	// 2b4c9bd7e3dafa35=Fname2 Lname2;;
	// ...
	if (isset($ini_sections["Contacts2"])) {
		foreach($ini_sections["Contacts2"] as $face_id => $face_name) {
			$face_ids[$face_id] = rtrim($face_name, ";");
		}
	} else {
		print "No contacts section found in {$picasa_file}, face tags will be unavailable.\n";
	}

	// The per-image section format is:
	// 	[xscan-0173.png]
	// 	faces=rect64(65723403d3b0e89c),84a18ca5ba06032e;rect64(3d600745f087703),f9ba0eb0b8dbac6a
	// 	backuphash=14029
	// 	albums=43822a6f7e2c1ecafac15663956c2ec9
	// 	caption=Jun. 2005
	// 	keywords=La Villette,Paris,France

	$image_infos = array();
	foreach ($ini_sections as $image_name => $ini_image_info) {
		if (!preg_match(IMAGE_FILE_REGEXP, $image_name)) {
			continue;
		}
		$image_file = dirname($picasa_file) . DIRECTORY_SEPARATOR . $image_name;
		if (!file_exists($image_file)) {
			// Note: .picasa.ini is known to contain a lot of stale entries (for deleted files, etc...),
			// so take this warning with a grain of salt.
			print "Warning: \"{$image_file}\" mentioned in .picasa.ini doesn't exist, skipping.\n";
			continue;
		}

		$info = new ImageInfo();

		$info->caption = isset($ini_image_info["caption"]) ? $ini_image_info["caption"] : "";
		if (isset($ini_image_info["keywords"])) {
			$info->keywords = array_map("trim", explode(",", $ini_image_info["keywords"]));
		}

		if (isset($ini_image_info["faces"])) {
			$faces = parse_faces_line($ini_image_info["faces"]);
			foreach($faces as $face) {
				if (!WRITE_UNKNOWN_FACES && $face->face_id === "ffffffffffffffff") {
					continue;
				}
				if (!isset($face_ids[$face->face_id])) {
					print "Warning: {$picasa_file} contains unknown face \"{$face->face_id}\" for image \"{$image_name}\", skipping face.\n";
					continue;
				}

				$face->name = $face_ids[$face->face_id];

				if (VERBOSE_OUTPUT) {
					$dim = getimagesize($image_file);
					$px_width = $dim[0]; $px_height = $dim[1];
					$px_rect = $face->rect->pixel_sized($px_width, $px_height);
					print "{$image_file}:\n";
					print "Face rectangle (x: {$px_rect->x}, y: {$px_rect->y}, w: {$px_rect->w}, h: {$px_rect->h}): {$face->name}\n";
				}

				if (WRITE_DUPLICATE_FACES) {
					$info->faces[] = $face;
				} else {
					if (!isset($info->faces[$face->face_id])) {
						$info->faces[$face->face_id] = $face;
					} else {
						print "Warning: {$picasa_file} contains duplicate face \"{$face->face_id}\" for image \"{$image_name}\", skipping face.\n";
						continue;
					}
				}
			}
		}

		$image_infos[$image_name] = $info;
	}

	return $image_infos;
}



function main($argc, $argv)
{
	if ($argc < 3) {  // no params
		print "Usage: {$argv[0]} <input_directory> <output_directory>\n";
		print ".picasa.ini files will be searched for in the input directory.\n";
		return 1;
	}
	
	$base_input_dir = $argv[1];
	$base_output_dir = $argv[2];
// 	$contacts_file = $argv[3];

	if (!is_dir($base_input_dir)) {
		print "Input is not a directory, exiting.\n";
		return 1;
	}
	if (!is_dir($base_output_dir)) {
		print "Output is not a directory, exiting.\n";
		return 1;
	}
	if (realpath($base_input_dir) === realpath($base_output_dir)) {
		print "Input and output directories are the same, exiting.\n";
		return 1;
	}

// 	if ($contacts_file !== "" && !is_readable($contacts_file)) {
// 		print "Contacts file is not readable, exiting\n";
// 		return 1;
// 	}
// 	$contacts = load_contacts($contacts_file);

	$all_input_files = array();
	rec_scandir($base_input_dir, IMAGE_FILE_REGEXP, $all_input_files);

	foreach ($all_input_files as $input_dir => $input_filenames) {
		print "Processing directory: {$input_dir}\n";
		$picasa_file = $input_dir . DIRECTORY_SEPARATOR . ".picasa.ini";
		if (!is_file($picasa_file) || !is_readable($picasa_file)) {
			print "Warning: \"{$picasa_file}\" doesn't exist or is not readable, skipping directory.\n";
			continue;
		}

		$image_infos = load_ini_image_infos($picasa_file);
		if ($image_infos === NULL) {
			print "Warning: \"{$picasa_file}\" could not be parsed, skipping directory.\n";
			continue;
		}

		$output_dir = str_replace($base_input_dir, $base_output_dir, $input_dir);
		if (!file_exists($output_dir)) {
			mkdir($output_dir, 0755, true);  // recursively
		}

		foreach($input_filenames as $input_filename) {
			$input_file = $input_dir . DIRECTORY_SEPARATOR . $input_filename;
			$output_file = $output_dir . DIRECTORY_SEPARATOR . $input_filename;

			if (OUTPUT_FORMAT === "")  {  // copy it if not changing the format
				copy($input_file, $output_file);

			} else {
				$input_ext = strtolower(pathinfo($input_file, PATHINFO_EXTENSION));
				$output_ext = strtolower(OUTPUT_FORMAT);

// 				if ($input_ext == $output_ext || ($input_ext === "jpg" && $output_ext === "jpeg") || ($input_ext === "jpeg" && $output_ext === "jpg")) {
// 					copy($input_file, $output_file);
// 				} else {
					// Convert unconditionally. This can be used to re-compress png files, for example.
					$output_file = preg_replace('/\\.[^.]+$/', "." . OUTPUT_FORMAT, $output_file);
					$cmd = sprintf("%s -quality %s %s %s",
							escapeshellarg(CONVERT_BINARY), escapeshellarg(OUTPUT_FORMAT_QUALITY),
							escapeshellarg($input_file), escapeshellarg($output_file));
					if (VERBOSE_OUTPUT) {
						print "$cmd\n";
					}
					system($cmd);
// 				}
			}

			if (!isset($image_infos[$input_filename])) {
				print "No .picasa.ini entry found for file \"{$input_file}\", not embedding tags.\n";
				continue;
			}

			$tag_args = array();
			$image_info = $image_infos[$input_filename];

			if (APPEND_KEYWORDS_TO_CAPTION && !empty($image_info->keywords)) {
				$kw_str = implode(", ", $image_info->keywords);
				$image_info->caption = ($image_info->caption === "" ? "" : ($image_info->caption . " - ")) . $kw_str;
			}

			if ($image_info->caption !== "") {
				// According to XMP spec, these are equivalent tags.
				// See http://metadataworkinggroup.com/pdf/mwg_guidance.pdf
				if (EXIFTOOL_MODE) {
					$caption = escapeshellarg($image_info->caption);
					if (WRITE_EXIF) {
						$tag_args[] = sprintf("-EXIF:ImageDescription=%s", $caption);  // ASCII, too error-prone
					}
					if (WRITE_IPTC) {
						$tag_args[] = sprintf("-IPTC:Caption-Abstract=%s", $caption);  // ASCII (?)
					}
					if (WRITE_XMP) {
						$tag_args[] = sprintf("-XMP:Description=%s", $caption);
					}
				} else {
					// See http://www.exiv2.org/sample.html for examples.
					if (WRITE_EXIF) {
						$tag_args[] = "-M " . escapeshellarg("set Exif.Image.ImageDescription Ascii " . $image_info->caption);
					}
					if (WRITE_IPTC) {
						$tag_args[] = "-M " . escapeshellarg("set Iptc.Application2.Caption " . escape_exiv_string($image_info->caption));
					}
					if (WRITE_XMP) {
						$tag_args[] = "-M " . escapeshellarg("set Xmp.dc.description LangAlt " . escape_exiv_string($image_info->caption));
					}
				}
			}

			if (!empty($image_info->keywords)) {
				foreach($image_info->keywords as $kw) {
					if (EXIFTOOL_MODE) {
						$kw = escapeshellarg($kw);
						if (WRITE_IPTC) {
							$tag_args[] = sprintf("-IPTC:Keywords=%s", $kw);
						}
						if (WRITE_XMP) {
							$tag_args[] = sprintf("-XMP:Subject=%s", $kw);
						}
					} else {
						if (WRITE_IPTC) {
							$tag_args[] = "-M " . escapeshellarg("add Iptc.Application2.Keywords " . escape_exiv_string($kw));
						}
						if (WRITE_XMP) {
							$tag_args[] = "-M " . escapeshellarg("set Xmp.dc.subject " . escape_exiv_string($kw));  // unordered list
						}
					}
				}
			}

			if (WRITE_XMP && WRITE_XMP_MWG && !empty($image_info->faces)) {
				$dim = getimagesize($output_file);
				$px_width = $dim[0]; $px_height = $dim[1];

				// XMP (MWG standard)
				if (EXIFTOOL_MODE) {
					$face_areas = array();
					foreach($image_info->faces as $face) {
						$center_x = round_xmp($face->rect->x + ($face->rect->w / 2));
						$center_y = round_xmp($face->rect->y + ($face->rect->h / 2));
						$w = round_xmp($face->rect->w);
						$h = round_xmp($face->rect->h);
						$name = escape_xmp($face->name);
						$area_str = "
						{
							Area = {
								W={$w}, H={$h}, X={$center_x}, Y={$center_y},
								Unit=normalized,
							},
							Name={$name},
							Type=Face,
						}";
						$face_areas[] = $area_str;
					}
					$face_areas_str = implode(",", $face_areas);

					$regions_str = "{
						AppliedToDimensions={
							W={$px_width}, H={$px_height},
							Unit=pixel,
						},
						RegionList=
						[
							$face_areas_str
						]
					}";
					$tag_args[] = sprintf("-RegionInfo=%s", escapeshellarg($regions_str));

				} else {  // Exiv2
					$tag_args[] = "-M " . escapeshellarg("set Xmp.mwg-rs.Regions/mwg-rs:AppliedToDimensions/stDim:w {$px_width}");
					$tag_args[] = "-M " . escapeshellarg("set Xmp.mwg-rs.Regions/mwg-rs:AppliedToDimensions/stDim:h {$px_height}");
					$tag_args[] = "-M " . escapeshellarg("set Xmp.mwg-rs.Regions/mwg-rs:AppliedToDimensions/stDim:unit pixel");

					$tag_args[] = "-M " . escapeshellarg("set Xmp.mwg-rs.Regions/mwg-rs:RegionList \"\"");

					foreach($image_info->faces as $i => $face) {
						$region_index = $i + 1;
						$center_x = round_xmp($face->rect->x + ($face->rect->w / 2));
						$center_y = round_xmp($face->rect->y + ($face->rect->h / 2));
						$w = round_xmp($face->rect->w);
						$h = round_xmp($face->rect->h);
						$tag_args[] = "-M " . escapeshellarg("set Xmp.mwg-rs.Regions/mwg-rs:RegionList[{$region_index}]/mwg-rs:Name " . escape_exiv_string($face->name));
						$tag_args[] = "-M " . escapeshellarg("set Xmp.mwg-rs.Regions/mwg-rs:RegionList[{$region_index}]/mwg-rs:Type Face");
						$tag_args[] = "-M " . escapeshellarg("set Xmp.mwg-rs.Regions/mwg-rs:RegionList[{$region_index}]/mwg-rs:Area/stArea:x {$center_x}");
						$tag_args[] = "-M " . escapeshellarg("set Xmp.mwg-rs.Regions/mwg-rs:RegionList[{$region_index}]/mwg-rs:Area/stArea:y {$center_y}");
						$tag_args[] = "-M " . escapeshellarg("set Xmp.mwg-rs.Regions/mwg-rs:RegionList[{$region_index}]/mwg-rs:Area/stArea:w {$w}");
						$tag_args[] = "-M " . escapeshellarg("set Xmp.mwg-rs.Regions/mwg-rs:RegionList[{$region_index}]/mwg-rs:Area/stArea:h {$h}");
						$tag_args[] = "-M " . escapeshellarg("set Xmp.mwg-rs.Regions/mwg-rs:RegionList[{$region_index}]/mwg-rs:Area/stArea:unit normalized");
					}
				}
			}

			if (WRITE_XMP && WRITE_XMP_WPG && !empty($image_info->faces)) {
				// XMP (MS Windows Photo Gallery extension)
				if (EXIFTOOL_MODE) {
					$face_areas = array();
					foreach($image_info->faces as $face) {
						$name = escape_xmp($face->name);
						$rect = $face->rect->rounded();
						$area_str = "
						{
							PersonDisplayName={$name},
							Rectangle={$rect->x}|, {$rect->y}|, {$rect->w}|, {$rect->h},
						}";
						$face_areas[] = $area_str;
					}
					$face_areas_str = implode(",", $face_areas);

					$regions_str = "{
						Regions=
						[
							$face_areas_str
						]
					}";
					$tag_args[] = sprintf("-RegionInfoMP=%s", escapeshellarg($regions_str));

				} else {  // Exiv2
					$tag_args[] = "-M " . escapeshellarg("set Xmp.MP.RegionInfo/MPRI:Regions \"\"");

					foreach($image_info->faces as $i => $face) {
						$region_index = $i + 1;
						$rect = $face->rect->rounded();
						$tag_args[] = "-M " . escapeshellarg("set Xmp.MP.RegionInfo/MPRI:Regions[{$region_index}]/MPReg:Rectangle "
								. "{$rect->x}, {$rect->y}, {$rect->w}, {$rect->h}");
						$tag_args[] = "-M " . escapeshellarg("set Xmp.MP.RegionInfo/MPRI:Regions[{$region_index}]/MPReg:PersonDisplayName "
								. escape_exiv_string($face->name));
					}
				}
			}

			if (empty($tag_args)) {
				print "No useful information found for file \"{$input_file}\", not embedding tags.\n";
				continue;
			}

			$cmd = "";
			if (EXIFTOOL_MODE) {
				$cmd = sprintf("%s -preserve -overwrite_original %s %s",
						escapeshellarg(EXIFTOOL_BINARY), implode(" ", $tag_args), escapeshellarg($output_file));
			} else {
				$cmd = sprintf("%s modify %s %s",
						escapeshellarg(EXIV2_BINARY), implode(" ", $tag_args), escapeshellarg($output_file));
			}
			if (VERBOSE_OUTPUT) {
				print "$cmd\n";
			}
			system($cmd);
		}
	}

	return 0;
}



exit(main($argc, $argv));



?>
