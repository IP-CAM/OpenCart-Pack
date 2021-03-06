<?php
class ControllerToolUpdatePack extends Controller {

	public function verify() {
		
		ignore_user_abort(true);
		
		$last_update = $this->config->get('update_pack_last');
		
		$ch = curl_init();
		curl_setopt($ch, CURLOPT_URL, 'http://www.valdeirsantana.com.br/index.php?route=opencart/update');
		curl_setopt($ch, CURLOPT_HEADER, false);
		curl_setopt($ch, CURLOPT_USERAGENT, $this->request->server['HTTP_USER_AGENT']);
		curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, false);
		curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
		curl_setopt($ch, CURLOPT_FORBID_REUSE, false);
		curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
		curl_setopt($ch, CURLOPT_POST, true);
		curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query(array(
			'last_update' => serialize($last_update)
		)));
		$json = curl_exec($ch);
		curl_close($ch);
		
		$this->response->addHeader('Content-Type: application/json');
		
		$this->session->data['update_pack_json'] = $json;
		
		$this->load->model('setting/setting');
		$this->model_setting_setting->editSetting('update_pack_date', array(
			'update_pack_date_last' => date('c', mktime(0,0,0, date('m'), date('d')+7, date('Y'))
		)));
		
		echo $json;
	}
	
	public function install() {
		ignore_user_abort(true);
		
		$this->log->write('Atualização Iniciada');
		
		/* Captura JSON com os update */
		if (isset($this->session->data['update_pack_json'])) {
			$json = $this->session->data['update_pack_json'];
		} else {
			ob_start();
			$this->verify();
			$json = ob_get_contents();
			ob_end_flush();
		}
		
		$update_id = isset($this->request->post['update_id']) ? $this->request->post['update_id'] : '';
		$updates = json_decode($json, true);
		
		if (isset($updates['updates'][$update_id])) {
			
			foreach($updates['updates'][$update_id] as $key => $update) {
				switch($key) {
					case 'zip':
						$this->zip($updates['updates'][$update_id]['zip']);
						break;
					
					case 'xml':
						$this->xml($updates['updates'][$update_id]['xml']);
						break;
						
					case 'sql':
						$this->sql($updates['updates'][$update_id]['sql']);
						break;
						
					case 'php':
						$this->php($updates['updates'][$update_id]['php']);
						break;
				}
			}
			
			$last_update = $this->config->get('update_pack_last');
			$last_update[] = $update_id;
			
			$this->load->model('setting/setting');
			$this->model_setting_setting->editSetting('update_pack', array(
				'update_pack_last' => $last_update
			));
		} else {
			return false;
		}
	}
	
	private function zip($data = array()) {
		/* Raiz da Loja */
		$file = DIR_APPLICATION . '../' . $data['filename'];
		
		$handle = copy($data['link'], $file);

		$zip = new ZipArchive();
		$zip->open($file);
			
		$zip->extractTo(DIR_APPLICATION . '../');
		$zip->close();
		
		unlink($file);
	}
	
	private function xml($data = array()) {
		/* Raiz da Loja */
		$file = DIR_APPLICATION . '../' . $data['filename'];
		
		copy($data['link'], $file);
		
		/* Carrega XML */
		$xml = simplexml_load_file($file);
		
		/* Carrega Model */
		$this->load->model('extension/modification');
		
		/* Captura Nome */
		if (isset($xml->name)) {
			$name = $xml->name;
		} else {
			$name = '';
		}
		
		/* Captura Código */
		if (isset($xml->code)) {
			
			$code = $xml->code;
			
			$modification_info = $this->model_extension_modification->getModificationByCode($code);
			
			if ($modification_info) {
				$modification_info = $this->model_extension_modification->deleteModificationByCode($code);
			}
		} else {
			$code = md5($data['filename']);
		}
		
		/* Captura Versão */
		if (isset($xml->version)) {
			$version = $xml->version;
		} else {
			$version = '';
		}
		
		/* Captura Autor */
		if (isset($xml->author)) {
			$author = $xml->author;
		} else {
			$author = '';
		}
		
		/* Captura Link */
		if (isset($xml->link)) {
			$link = $xml->link;
		} else {
			$link = '';
		}
		
		/* Salva a modificação */
		$modification_data = array(
			'name'    => $name,
			'code'    => $code,
			'author'  => $author,
			'version' => $version,
			'link'    => $link,
			'xml'     => $xml->saveXML(),
			'status'  => 1
		);
		
		$this->model_extension_modification->addModification($modification_data);
		
		$this->refreshModification();
		
		unlink($file);
	}

	private function sql($data = array()) {
		/* Raiz da Loja */
		$file = DIR_APPLICATION . '../' . $data['filename'];
		
		copy($data['link'], $file);
		
		/* Abre arquivo */
		$file = file($file);

		foreach($file as $line) {
			$this->db->query($line);
		}
		
		unlink($file);
	}
	
	private function php($data = array()) {
		/* Raiz da Loja */
		$file = DIR_APPLICATION . '../' . $data['filename'];
		
		copy($data['link'], $file);
		
		$content_file = str_replace(array('<#php', '#>'), array('<?php', '?>'), file_get_contents($file));
		
		$handle = fopen($file, 'w');
		
		fwrite($handle, $content_file);
		
		fclose($handle);
		
		include $file;
		
		unlink($file);
	}
	
	private function refreshModification() {
		$this->load->language('extension/modification');

		$this->document->setTitle($this->language->get('heading_title'));

		$this->load->model('extension/modification');

		// Just before files are deleted, if config settings say maintenance mode is off then turn it on
		if (!$this->config->get('config_maintenance')) {
			$this->load->model('setting/setting');

			$this->model_setting_setting->editSettingValue('config', 'config_maintenance', true);
		}

		//Log
		$log = array();

		// Clear all modification files
		$files = array();

		// Make path into an array
		$path = array(DIR_MODIFICATION . '*');

		// While the path array is still populated keep looping through
		while (count($path) != 0) {
			$next = array_shift($path);

			foreach (glob($next) as $file) {
				// If directory add to path array
				if (is_dir($file)) {
					$path[] = $file . '/*';
				}

				// Add the file to the files to be deleted array
				$files[] = $file;
			}
		}

		// Reverse sort the file array
		rsort($files);

		// Clear all modification files
		foreach ($files as $file) {
			if ($file != DIR_MODIFICATION . 'index.html') {
				// If file just delete
				if (is_file($file)) {
					unlink($file);

				// If directory use the remove directory function
				} elseif (is_dir($file)) {
					rmdir($file);
				}
			}
		}

		// Begin
		$xml = array();

		// Load the default modification XML
		$xml[] = file_get_contents(DIR_SYSTEM . 'modification.xml');

		// This is purly for developers so they can run mods directly and have them run without upload sfter each change.
		$files = glob(DIR_SYSTEM . '*.ocmod.xml');

		if ($files) {
			foreach ($files as $file) {
				$xml[] = file_get_contents($file);
			}
		}

		// Get the default modification file
		$results = $this->model_extension_modification->getModifications();

		foreach ($results as $result) {
			if ($result['status']) {
				$xml[] = $result['xml'];
			}
		}

		$modification = array();

		foreach ($xml as $xml) {
			$dom = new DOMDocument('1.0', 'UTF-8');
			$dom->preserveWhiteSpace = false;
			$dom->loadXml($xml);

			// Log
			$log[] = 'MOD: ' . $dom->getElementsByTagName('name')->item(0)->textContent;

			// Wipe the past modification store in the backup array
			$recovery = array();

			// Set the a recovery of the modification code in case we need to use it if an abort attribute is used.
			if (isset($modification)) {
				$recovery = $modification;
			}

			$files = $dom->getElementsByTagName('modification')->item(0)->getElementsByTagName('file');

			foreach ($files as $file) {
				$operations = $file->getElementsByTagName('operation');

				$files = explode(',', $file->getAttribute('path'));

				foreach ($files as $file) {
					$path = '';

					// Get the full path of the files that are going to be used for modification
					if (substr($file, 0, 7) == 'catalog') {
						$path = DIR_CATALOG . str_replace('../', '', substr($file, 8));
					}

					if (substr($file, 0, 5) == 'admin') {
						$path = DIR_APPLICATION . str_replace('../', '', substr($file, 6));
					}

					if (substr($file, 0, 6) == 'system') {
						$path = DIR_SYSTEM . str_replace('../', '', substr($file, 7));
					}

					if ($path) {
						$files = glob($path);

						if ($files) {
							foreach ($files as $file) {
								// Get the key to be used for the modification cache filename.
								if (substr($file, 0, strlen(DIR_CATALOG)) == DIR_CATALOG) {
									$key = 'catalog/' . substr($file, strlen(DIR_CATALOG));
								}

								if (substr($file, 0, strlen(DIR_APPLICATION)) == DIR_APPLICATION) {
									$key = 'admin/' . substr($file, strlen(DIR_APPLICATION));
								}

								if (substr($file, 0, strlen(DIR_SYSTEM)) == DIR_SYSTEM) {
									$key = 'system/' . substr($file, strlen(DIR_SYSTEM));
								}

								// If file contents is not already in the modification array we need to load it.
								if (!isset($modification[$key])) {
									$content = file_get_contents($file);

									$modification[$key] = preg_replace('~\r?\n~', "\n", $content);
									$original[$key] = preg_replace('~\r?\n~', "\n", $content);

									// Log
									$log[] = 'FILE: ' . $key;
								}

								foreach ($operations as $operation) {
									$error = $operation->getAttribute('error');

									// Ignoreif
									$ignoreif = $operation->getElementsByTagName('ignoreif')->item(0);

									if ($ignoreif) {
										if ($ignoreif->getAttribute('regex') != 'true') {
											if (strpos($modification[$key], $ignoreif->textContent) !== false) {
												continue;
											}
										} else {
											if (preg_match($ignoreif->textContent, $modification[$key])) {
												continue;
											}
										}
									}

									$status = false;

									// Search and replace
									if ($operation->getElementsByTagName('search')->item(0)->getAttribute('regex') != 'true') {
										// Search
										$search = $operation->getElementsByTagName('search')->item(0)->textContent;
										$trim = $operation->getElementsByTagName('search')->item(0)->getAttribute('trim');
										$index = $operation->getElementsByTagName('search')->item(0)->getAttribute('index');

										// Trim line if no trim attribute is set or is set to true.
										if (!$trim || $trim == 'true') {
											$search = trim($search);
										}

										// Add
										$add = $operation->getElementsByTagName('add')->item(0)->textContent;
										$trim = $operation->getElementsByTagName('add')->item(0)->getAttribute('trim');
										$position = $operation->getElementsByTagName('add')->item(0)->getAttribute('position');
										$offset = $operation->getElementsByTagName('add')->item(0)->getAttribute('offset');

										if ($offset == '') {
											$offset = 0;
										}

										// Trim line if is set to true.
										if ($trim == 'true') {
											$add = trim($add);
										}

										// Log
										$log[] = 'CODE: ' . $search;

										// Check if using indexes
										if ($index !== '') {
											$indexes = explode(',', $index);
										} else {
											$indexes = array();
										}

										// Get all the matches
										$i = 0;

										$lines = explode("\n", $modification[$key]);

										for ($line_id = 0; $line_id < count($lines); $line_id++) {
											$line = $lines[$line_id];

											// Status
											$match = false;

											// Check to see if the line matches the search code.
											if (stripos($line, $search) !== false) {
												// If indexes are not used then just set the found status to true.
												if (!$indexes) {
													$match = true;
												} elseif (in_array($i, $indexes)) {
													$match = true;
												}

												$i++;
											}

											// Now for replacing or adding to the matched elements
											if ($match) {
												switch ($position) {
													default:
													case 'replace':
														$new_lines = explode("\n", $add);

														if ($offset < 0) {
															array_splice($lines, $line_id + $offset, abs($offset) + 1, array(str_replace($search, $add, $line)));

															$line_id -= $offset;
														} else {
															array_splice($lines, $line_id, $offset + 1, array(str_replace($search, $add, $line)));
														}

														break;
													case 'before':
														$new_lines = explode("\n", $add);

														array_splice($lines, $line_id - $offset, 0, $new_lines);

														$line_id += count($new_lines);
														break;
													case 'after':
														$new_lines = explode("\n", $add);

														array_splice($lines, ($line_id + 1) + $offset, 0, $new_lines);

														$line_id += count($new_lines);
														break;
												}

												// Log
												$log[] = 'LINE: ' . $line_id;

												$status = true;
											}
										}

										$modification[$key] = implode("\n", $lines);
									} else {
										$search = $operation->getElementsByTagName('search')->item(0)->textContent;
										$limit = $operation->getElementsByTagName('search')->item(0)->getAttribute('limit');
										$replace = $operation->getElementsByTagName('add')->item(0)->textContent;

										// Limit
										if (!$limit) {
											$limit = -1;
										}

										// Log
										$match = array();

										preg_match_all($search, $modification[$key], $match, PREG_OFFSET_CAPTURE);

										// Remove part of the the result if a limit is set.
										if ($limit > 0) {
											$match[0] = array_slice($match[0], 0, $limit);
										}

										if ($match[0]) {
											$log[] = 'REGEX: ' . $search;

											for ($i = 0; $i < count($match[0]); $i++) {
												$log[] = 'LINE: ' . (substr_count(substr($modification[$key], 0, $match[0][$i][1]), "\n") + 1);
											}

											$status = true;
										}

										// Make the modification
										$modification[$key] = preg_replace($search, $replace, $modification[$key], $limit);
									}

									if (!$status) {
										// Log
										$log[] = 'NOT FOUND!';

										// Skip current operation
										if ($error == 'skip') {
											break;
										}

										// Abort applying this modification completely.
										if ($error == 'abort') {
											$modification = $recovery;

											// Log
											$log[] = 'ABORTING!';

											break 5;
										}
									}
								}
							}
						}
					}
				}
			}

			// Log
			$log[] = '----------------------------------------------------------------';
		}

		// Log
		$ocmod = new Log('ocmod.log');
		$ocmod->write(implode("\n", $log));

		// Write all modification files
		foreach ($modification as $key => $value) {
			// Only create a file if there are changes
			if ($original[$key] != $value) {
				$path = '';

				$directories = explode('/', dirname($key));

				foreach ($directories as $directory) {
					$path = $path . '/' . $directory;

					if (!is_dir(DIR_MODIFICATION . $path)) {
						@mkdir(DIR_MODIFICATION . $path, 0777);
					}
				}

				$handle = fopen(DIR_MODIFICATION . $key, 'w');

				fwrite($handle, $value);

				fclose($handle);
			}
		}

		// Just after modifications are complete, if config settings say maintenance mode is off then turn it back off.
		if (!$this->config->get('config_maintenance')) {
			$this->model_setting_setting->editSettingValue('config', 'config_maintenance', false);
		}
	}

	private function remember() {
		$this->load->model('setting/setting');
		$this->model_setting_setting->editSetting('update_pack_date', array(
			'update_pack_date_last' => date('c', mktime(0,0,0, date('m'), date('d')+7, date('Y'))
		)));
		return true;
	}
}