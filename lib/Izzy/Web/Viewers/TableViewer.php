<?php

namespace Izzy\Web\Viewers;

/**
 * Base class for table display
 */
class TableViewer
{
    private array $columns = [];
    private array $data = [];
    private string $caption = '';
    private array $options = [];
    
    public function __construct(array $options = [])
    {
        $this->options = array_merge([
            'class' => '',
            'striped' => true,
            'hover' => true,
            'bordered' => true,
            'compact' => false
        ], $options);
    }
    
    public function setCaption(string $caption): self
    {
        $this->caption = $caption;
        return $this;
    }
    
    public function addColumn(string $key, string $title, array $options = []): self
    {
        $this->columns[$key] = array_merge([
            'title' => $title,
            'align' => 'left',
            'width' => 'auto',
            'format' => 'text',
            'class' => ''
        ], $options);
        return $this;
    }
    
    public function setData(array $data): self
    {
        $this->data = $data;
        return $this;
    }
    
    public function setOptions(array $options): self
    {
        $this->options = array_merge($this->options, $options);
        return $this;
    }
    
    public function render(): string
    {
        $html = '<table class="' . $this->getTableClass() . '">';
        
        if (!empty($this->caption)) {
            $html .= '<caption>' . htmlspecialchars($this->caption) . '</caption>';
        }
        
        $html .= '<thead><tr>';
        foreach ($this->columns as $column) {
            $html .= '<th class="' . $column['class'] . '" style="text-align: ' . $column['align'] . '; width: ' . $column['width'] . ';">';
            $html .= htmlspecialchars($column['title']);
            $html .= '</th>';
        }
        $html .= '</tr></thead>';
        
        $html .= '<tbody>';
        foreach ($this->data as $index => $row) {
            $rowClass = $this->getRowClass($index);
            $html .= '<tr class="' . $rowClass . '">';
            
            foreach ($this->columns as $key => $column) {
                $value = $row[$key] ?? '';
                $formattedValue = $this->formatValue($value, $column['format']);
                
                $html .= '<td class="' . $column['class'] . '" style="text-align: ' . $column['align'] . ';">';
                $html .= htmlspecialchars($formattedValue);
                $html .= '</td>';
            }
            $html .= '</tr>';
        }
        $html .= '</tbody></table>';
        
        return $html;
    }
    
    private function getTableClass(): string
    {
        $classes = ['dca-table'];
        
        if (!empty($this->options['class'])) {
            $classes[] = $this->options['class'];
        }
        
        return implode(' ', $classes);
    }
    
    private function getRowClass(int $index): string
    {
        $classes = [];
        
        if ($this->options['striped'] && $index % 2 === 1) {
            $classes[] = 'even-row';
        }
        
        return implode(' ', $classes);
    }
    
    private function formatValue($value, string $format): string
    {
        // If value is already formatted (contains currency symbols or %), return as is
        if (is_string($value) && (strpos($value, 'USDT') !== false || strpos($value, '%') !== false)) {
            return $value;
        }
        
        switch ($format) {
            case 'number':
                return is_numeric($value) ? number_format($value, 2) : $value;
            case 'currency':
                return is_numeric($value) ? number_format($value, 2) . ' USDT' : $value;
            case 'percent':
                return is_numeric($value) ? number_format($value, 2) . '%' : $value;
            default:
                return $value;
        }
    }
} 