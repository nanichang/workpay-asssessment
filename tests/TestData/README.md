# Test Data Files

This directory contains sample data files used for testing the Employee Import System.

## Files

### good-employees.csv
- **Purpose**: Performance testing with clean, valid employee data
- **Size**: 20,000+ rows
- **Content**: Valid employee records with proper formatting
- **Usage**: Used to test system performance and successful import scenarios

### bad-employees.csv  
- **Purpose**: Validation error testing
- **Size**: 20 rows
- **Content**: Employee records with intentional validation errors
- **Usage**: Used to test validation logic and error handling

### Assessment Data Set.xlsx
- **Purpose**: Excel file processing testing
- **Format**: Excel (.xlsx)
- **Usage**: Used to test Excel file import functionality

## Integration with Tests

These files are integrated with the test suite to provide realistic testing scenarios:

1. **Performance Tests**: Use good-employees.csv to test processing speed and memory efficiency
2. **Validation Tests**: Use bad-employees.csv to verify error detection and handling
3. **Format Tests**: Use Assessment Data Set.xlsx to test Excel file processing

## File Locations

The actual test files are located in the project root:
- `../../good-employees.csv`
- `../../bad-employees.csv` 
- `../../Assement Data Set.xlsx`

Tests reference these files using `base_path()` helper to ensure consistent access across different environments.