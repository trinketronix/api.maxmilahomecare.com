<?php

declare(strict_types=1);

namespace Api\Controllers;

use Api\Models\Tool;
use Exception;

class ToolController extends BaseController {
    /**
     * Create a new tool
     *
     * @return array Response data
     */
    public function create(): array {
        try {
            $data = $this->getRequestBody();

            // Validate required fields
            $requiredFields = [
                Tool::NAME => 'Name is required',
                Tool::DESCRIPTION => 'Description is required'
            ];

            foreach ($requiredFields as $field => $message) {
                if (empty($data[$field])) {
                    return $this->respondWithError($message, 400);
                }
            }

            // Create the tool within a transaction
            return $this->withTransaction(function() use ($data) {
                $tool = new Tool();

                // Set required fields
                $tool->name = $data[Tool::NAME];
                $tool->description = $data[Tool::DESCRIPTION];

                // Set optional fields if provided
                if (isset($data[Tool::MATERIAL])) {
                    $tool->material = $data[Tool::MATERIAL];
                }

                if (isset($data[Tool::INVENTOR])) {
                    $tool->inventor = $data[Tool::INVENTOR];
                }

                if (isset($data[Tool::YEAR])) {
                    $tool->year = (int)$data[Tool::YEAR];
                }

                // Save the tool
                if (!$tool->save()) {
                    return $this->respondWithError($tool->getMessages(), 422);
                }

                return $this->respondWithSuccess([
                    'message' => 'Tool created successfully',
                    'id' => $tool->id,
                    'tool' => $tool->toArray()
                ], 201);
            });

        } catch (Exception $e) {
            $message = $e->getMessage() . ' ' . $e->getTraceAsString() . ' ' . $e->getFile() . ' ' . $e->getLine();
            error_log('Exception: ' . $message);
            return $this->respondWithError('Exception: ' . $e->getMessage(), 400);
        }
    }

    /**
     * Get all tools or search by query parameter
     *
     * @return array Response data
     */
    public function getAll(): array {
        try {
            $query = $this->request->getQuery('q', 'string', '');
            $material = $this->request->getQuery('material', 'string', '');
            $inventor = $this->request->getQuery('inventor', 'string', '');
            $year = $this->request->getQuery('year', 'int', 0);

            // Apply filters if provided
            if (!empty($query)) {
                $tools = Tool::search($query);
            } else if (!empty($material)) {
                $tools = Tool::findByMaterial($material);
            } else if (!empty($inventor)) {
                $tools = Tool::findByInventor($inventor);
            } else if ($year > 0) {
                $tools = Tool::find([
                    'conditions' => 'year = :year:',
                    'bind' => ['year' => $year],
                    'bindTypes' => ['year' => \PDO::PARAM_INT]
                ]);
            } else {
                // Get all tools
                $tools = Tool::find([
                    'order' => 'name ASC'
                ]);
            }

            if ($tools->count() === 0) {
                return $this->respondWithSuccess([], 200);
            }

            return $this->respondWithSuccess([
                'count' => $tools->count(),
                'tools' => $tools->toArray()
            ]);

        } catch (Exception $e) {
            $message = $e->getMessage() . ' ' . $e->getTraceAsString() . ' ' . $e->getFile() . ' ' . $e->getLine();
            error_log('Exception: ' . $message);
            return $this->respondWithError('Exception: ' . $e->getMessage(), 400);
        }
    }

    /**
     * Get a tool by ID
     *
     * @param int $id Tool ID
     * @return array Response data
     */
    public function getById(int $id): array {
        try {
            $tool = Tool::findFirst($id);

            if (!$tool) {
                return $this->respondWithError('Tool not found', 404);
            }

            return $this->respondWithSuccess($tool->toArray());

        } catch (Exception $e) {
            $message = $e->getMessage() . ' ' . $e->getTraceAsString() . ' ' . $e->getFile() . ' ' . $e->getLine();
            error_log('Exception: ' . $message);
            return $this->respondWithError('Exception: ' . $e->getMessage(), 400);
        }
    }

    /**
     * Update a tool
     *
     * @param int $id Tool ID
     * @return array Response data
     */
    public function update(int $id): array {
        try {
            $tool = Tool::findFirst($id);

            if (!$tool) {
                return $this->respondWithError('Tool not found', 404);
            }

            $data = $this->getRequestBody();

            // Update the tool within a transaction
            return $this->withTransaction(function() use ($tool, $data) {
                // Update fields if provided
                $updateableFields = [
                    Tool::NAME,
                    Tool::DESCRIPTION,
                    Tool::MATERIAL,
                    Tool::INVENTOR,
                    Tool::YEAR
                ];

                foreach ($updateableFields as $field) {
                    if (isset($data[$field])) {
                        if ($field === Tool::YEAR) {
                            $tool->$field = (int)$data[$field];
                        } else {
                            $tool->$field = $data[$field];
                        }
                    }
                }

                // Save the tool
                if (!$tool->save()) {
                    return $this->respondWithError($tool->getMessages(), 422);
                }

                return $this->respondWithSuccess([
                    'message' => 'Tool updated successfully',
                    'tool' => $tool->toArray()
                ]);
            });

        } catch (Exception $e) {
            $message = $e->getMessage() . ' ' . $e->getTraceAsString() . ' ' . $e->getFile() . ' ' . $e->getLine();
            error_log('Exception: ' . $message);
            return $this->respondWithError('Exception: ' . $e->getMessage(), 400);
        }
    }

    /**
     * Delete a tool
     *
     * @param int $id Tool ID
     * @return array Response data
     */
    public function delete(int $id): array {
        try {
            $tool = Tool::findFirst($id);

            if (!$tool) {
                return $this->respondWithError('Tool not found', 404);
            }

            // Delete the tool within a transaction
            return $this->withTransaction(function() use ($tool) {
                if (!$tool->delete()) {
                    return $this->respondWithError($tool->getMessages(), 422);
                }

                return $this->respondWithSuccess([
                    'message' => 'Tool deleted successfully',
                    'id' => $tool->id
                ]);
            });

        } catch (Exception $e) {
            $message = $e->getMessage() . ' ' . $e->getTraceAsString() . ' ' . $e->getFile() . ' ' . $e->getLine();
            error_log('Exception: ' . $message);
            return $this->respondWithError('Exception: ' . $e->getMessage(), 400);
        }
    }
}